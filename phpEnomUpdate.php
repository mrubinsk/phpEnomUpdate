#!/usr/local/bin/php
<?php
/**
 * $Id: phpEnomUpdate.php,v 1.4 2007/12/22 20:31:11 mrubinsk Exp $
 * Script to update dynamic IP address services that use name-services.com
 * style interfaces. (Tested to work with hostdepartment.com dns services.
 * This script requires the following PEAR modules:
 *
 * HTTP_Request
 * Console_GetOpt
 *
 * and the Horde_CLI module available from pear.horde.org.
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author Michael Rubinsky <mike@theupstairsroom.com>
 *
 */
require_once 'Horde/CLI.php';
require_once 'HTTP/Request.php';

if (!Horde_CLI::runningFromCLI()) {
    exit("Must be run from the command line\n");
}
Horde_CLI::init();
$cli = Horde_CLI::singleton();

// Get the command line options
require_once 'Console/Getopt.php';
$ret = Console_Getopt::getopt(Console_Getopt::readPHPArgv(), 'hu:p:lc:g:a:d:',
                              array('help', 'conf='));

list($opts, $args) = $ret;
if (!$opts) {
    showHelp();
    exit;
}
foreach ($opts as $opt) {
    list($optName, $optValue) = $opt;
    switch ($optName) {
    case 'c':
    case '--conf':
        $configfile = $optValue;
        break;
    case 'h':
    case '--help':
        showHelp();
        exit;
    }
}

if (empty($configfile)) {
    $configfile = '/etc/phpEnomUpdate.conf';
}

// Pull in the config file.
require $configfile;

// Make sure we have the variables we need.
if (empty($set_ip) || empty($get_ip) || empty($zones)) {
    exit("You must provide a proper config file.\n");
}

$enom = new EnomUpdate($get_ip, $set_ip);
foreach ($zones as $zone) {
    $result = $enom->update($zone);
    if (!$result instanceof PEAR_Error) {
        $cli->writeln($cli->green(sprintf("%s updated successfully.", $zone['zone'])));
    } else {
        $cli->writeln($cli->red(sprintf("%s updated FAILED.", $zone['zone'])));
    }
}
exit;

function showHelp()
{
   global $cli;

   $cli->writeln(sprintf("Usage %s [OPTIONS]...", basename(__FILE__)));
   $cli->writeln();
   $cli->writeln('-h --help                 Show this help');
   $cli->writeln('-c --conf[=filepath]      The full path to the configuration file');
   $cli->writeln();
}


class EnomUpdate {

    /**
     * @var string $ip  Holds the current ip address
     * @access private
     */
    private $ip;

    /**
     * @var string $ip_url  The URL to retrieve the IP address from
     * @access private
     */
    private $ip_url;

    /**
     * @var string $update_url  The URL of the DNS service to pass the new IP to
     * @access private
     */
    private $update_url;


    /**
     * Constructor
     *
     * @param string $url  The URL to use to obtain the IP address.
     */
    public function __construct($ip_fetchurl, $ip_seturl)
    {
        $this->ip_url = $ip_fetchurl;
        $this->update_url = $ip_seturl;
    }

    /**
     * Returns the ip address present on the webpage.
     * This assumes that the ip is returned as it is with checkip.dyndns.org
     *
     * @return mixed  String containing the IP address | PEAR_Error
     */
    public function getIp()
    {
        if (empty($this->ip_url)) {
            return PEAR::raiseError('No URL passed.');
        }

        require_once 'HTTP/Request.php';
        $request = new HTTP_Request($this->ip_url);
        $result = $request->sendRequest();
        if ($result instanceof PEAR_Error) {
            return $result;
        }

        // Yea, kinda sloppy, but suits our purpose.
        $body = $request->getResponseBody();
        $nodes = preg_split('/<[^>]*>/', $body);
        foreach ($nodes as $node) {
            $test = preg_match("/Current IP Address: ((\d+\.){3}\d+)/",
                               $request->getResponseBody(), $matches);
            if ($test) {
                return $matches[1];
            }
        }

        return PEAR::raiseError("Could not determine your IP address.");
    }

    /**
     * Sends a properly formatted HTTP Request to the DNS provider
     *
     * The URL to send should look like this:
     * http://updateurl.com/interface.asp?Command=SetDNSHost&Zone={myDomain}&DomainPassword={myPassword}&HostName={hostName}&Address={ipAddress}
     *
     * @param array $params  Holds all the host parameters needed.
     *
     * @return true | PEAR_Error
     */
    public function update($params)
    {
        // First check if we already have an IP address.
        if (empty($this->ip) || empty($this->update_url)) {
            $this->ip = $this->getIp();
            if ($this->ip instanceof PEAR_Error) {
                return $this->ip;
            }
        }

        // Perform a request for each host provided for this zone.
        foreach ($params['hosts'] as $hostname) {
            $request = new HTTP_Request($this->update_url,
                                        array('method' => 'GET'));

            $request->addQueryString('Command', 'SetDNSHost');
            $request->addQueryString('Zone', $params['zone']);
            $request->addQueryString('DomainPassword', $params['password']);
            $request->addQueryString('HostName', $hostname);
            $request->addQueryString('Address', $this->ip);
            $result = $request->sendRequest();
            if ($result instanceof PEAR_Error) {
                return $result;
            }
        }
        return true;
    }


}

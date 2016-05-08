<?php
/**
 * Quick hack to perform a backup using DB creds from LocalSettings.php
 *
 * Copyright © 2014 Mark A. Hershberger <mah@nichework.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

$basePath = getenv( 'MW_INSTALL_PATH' ) !== false ? getenv( 'MW_INSTALL_PATH' ) : __DIR__;

$maint = $basePath . '/maintenance/Maintenance.php';
if ( ! file_exists( $maint ) ) {
    die "Please set the MW_INSTALL_PATH environment variable to your MediaWiki installationn's directory\n" .
		"or put this script in the same directory as index.php for your wiki.\n";
}
require_once $maint;

/**
 * Maintenance script that performs a SQL backup
 *
 * @ingroup Maintenance
 */
class backupDB extends Maintenance {

    const progressIndicator = "/usr/bin/pv";
    const defaultDateFormat  = "%Y-%m-%d";
    const defaultCompressor = "/usr/bin/gzip";
    const defaultSuffix     = "gz";
    const defaultOpts       = "--skip-opt";
    const defaultCopts      = "-9";
    const defaultDump       = "/usr/bin/mysqldump";

    private $credFile;

    public function __construct() {
        parent::__construct();
        $this->mDescription = "Dump the database.";
        $this->addOption( "dir", "Directory to hold dumps", true, true, "d" );
        $this->addOption( "compr", "Compression binary to use. (".
            self::defaultCompressor.")", false, true, "c" );
        $this->addOption( "suffix", "Suffix to put on the files. (".
            self::defaultSuffix.")", false, true, "s" );
        $this->addOption( "dateFormat", "Format to use for date. (".
            self::defaultDateFormat.")", false, true, "f" );
        $this->addOption( "prog", "Tool to use for a progress indicator. ".
            "Only used for interactive shells. (".
            self::progressIndicator.")", false, true, "p" );
        $this->addOption( "copt", "Options to pass to compression program. (".
            self::defaultCopts.")", false, true, "o" );
        $this->addOption( "opt", "Options to pass to dumper program. (".
            self::defaultOpts.")", false, true, "o" );
        $this->addOption( "dumper", "Program to use for doing the dump. (".
            self::defaultDump.")", false, true, "u" );
        $this->addOption( "skip", "Do everything except dumping.", false,
            false, "n" );
        $this->addOption( "keep", "Keep any credentials file instead of " .
            "deleting it.", false, false, "k" );
    }

    public function execute() {
        register_shutdown_function( array( $this, 'shutdown' ) );
        $dumper = $this->getDumper() . $this->getPipeCapture();
        $this->outputTTY( "Executing: $dumper\n" );
        return $this->doDump( $dumper );
    }

    public function doDump( $dumper ) {
        $skip = $this->getOption( "skip" );
        $ret = null;
        if ( $skip ) {
            $this->outputTTY( "\n(Skipping actual dump.)\n\n" );
        } else {
            if( system( $dumper, $ret ) === false ) {
                $this->outputTTY( "FAILED with return code: $ret\n" );
            }
        }
        return $ret;
    }

    public function shutdown() {
        $keep = $this->getOption( "keep" );
        if ( ! $keep && $this->credFile ) {
            $this->outputTTY( "Removing cred file ... " );
            if ( file_exists( $this->credFile ) ) {
                if ( unlink( $this->credFile ) === false ) {
                    $err = error_get_last();
                    $this->outputTTY( "Error removing {$this->credFile}: " .
                        $err['message'] . "\n" );
                } else {
                    $this->outputTTY( "OK\n" );
                }
            } else {
                $this->outputTTY( "No file at {$this->credFile}? " .
                    "Who removed it?\n" );
            }
        } else {
            $this->outputTTY( "Credentials file left at " .
                $this->credFile . ".\n" );
        }
    }

    public function outputTTY( $msg ) {
        if ( $this->posix_isatty( STDIN ) ) {
            $this->output( $msg );
        } else {
            wfDebug( $msg );
        }
    }

    public function getCreds() {
        global $wgDBpassword, $wgDBuser, $wgDBserver;

        $file = tempnam( "/tmp", "" );
        if ( $file !== false ) {
            $out =<<<EOT
[client]
user      = $wgDBuser
password  = $wgDBpassword
host      = $wgDBserver

EOT;
            file_put_contents( $file, $out );
        } else {
            $a = error_get_last();
            $this->error( "Trouble creating credential file:\n" .
                $a['message'], true );
        }
        $this->credFile = $file;
        return $file;
    }

    public function getDumper() {
        global $wgDBname;
        $credFile = $this->getCreds();
        $dbdump = $this->getOption( "dumper", self::defaultDump );
        $opt    = $this->getOption( "opt", self::defaultOpts );
        if ( !is_executable( $dbdump ) ) {
            $this->error( "No executable at '$compr'.  " .
                "Please provide the full path to a compression executable.",
                true );
        }

        return "$dbdump --defaults-extra-file=$credFile $opt $wgDBname";
    }

    public function getPipeCapture() {
        global $wgDBserver;
        $dir    = $this->getOption( "dir" );
        $suffix = $this->getOption( "suffix", self::defaultSuffix );
        $format = $this->getOption( "format", self::defaultDateFormat );
        $compr  = $this->getOption( "compr", self::defaultCompressor );
        $copt   = $this->getOption( "copt", self::defaultCopts);
        $prog   = $this->getOption( "prog", self::progressIndicator );
        $date   = strftime( $format );
        $dumpFile = "$dir/dump-$wgDBserver-$date.$suffix";
        $progress = "";

        if ( file_exists( $dumpFile ) ) {
            $this->error( "File already exists at $dumpFile, refusing to " .
                "overwrite.", true );
        }

        if ( !is_dir( $dir ) ) {
            $this->error( "The directory '$dir' doesn't exist.", true );
        }

        if ( !is_writable( $dir ) ) {
            $this->error( "The directory '$dir' isn't writable.", true );
        }

        if ( !is_executable( $compr ) ) {
            $this->error( "No executable at '$compr'.  " .
                "Please provide the full path to a compression executable.",
                true );
        }

        $this->outputTTY( "Backing up $wgDBserver to $dumpFile ...\n");
        if ( $this->posix_isatty( STDOUT )
            && is_executable( $prog ) ) {
            $progress = $prog . " |";
        } else {
            $this->outputTTY( "($prog not found, so" .
                " there is no progress indicator.)\n" );
        }

        return "| $progress $compr $copt > $dumpFile";
    }

}

$maintClass = "backupDB";
require_once( RUN_MAINTENANCE_IF_MAIN );

<?php

/**
 * Original copyright
 *
 * SFTP class using PHPs ssh2 features.
 * This class utilises a fair bit from the CodeIgniter FTP class.
 *
 * @package     CodeIgniter
 * @subpackage  Libraries
 * @category    Sftp
 * @author      Andy Lyon
 */

 /**
 * title
 *
 * desc
 *
 * @package		SFSftp-Bundle
 * @subpackage	
 * @category	
 * @author		Achraf Soltani <soltani.achraf@gmail.com>
 * @link		http://www.achrafsoltani.com
 * @date        11/19/2013
 */

namespace SFSftp;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class SFSftp
{

    var $hostname	= '';
    var $username	= '';
    var $password	= '';
    var $port		= 22;
    var $debug		= FALSE;
    var $conn_sftp	= FALSE;
    var $login_via_key = FALSE;
    var $public_key_url = '';
    var $private_key_url = '';

    var $buffer_size = 1024;
    var $errors;
    var $logger;

    public function __construct($config = array())
    {
        $this->logger = new Logger('sftp');
        $this->logger->pushHandler(new StreamHandler('app/logs/sftp.log', Logger::INFO));

        $this->errors = array();

        if (count($config) > 0)
        {
            $this->initialize($config);
        }

        $this->logger->addNotice("SFTP Class Initialized");
    }

    function initialize($config = array())
    {
        foreach ($config as $key => $val)
        {
            if (isset($this->$key))
            {
                $this->$key = $val;
            }
        }

        // Prep the hostname
        $this->hostname = preg_replace('|.+?://|', '', $this->hostname);
    }

    function _login()
    {
        if ($this->login_via_key) {
            if (@ssh2_auth_pubkey_file($this->conn, $this->username, $this->public_key_url, $this->private_key_url, $this->password)) {
                return true;
            } else {
                if ($this->debug == TRUE)
                {
                    $this->_error('sftp_unable_to_connect_with_public_key');
                }
                return false;
            }
        } else {
            return @ssh2_auth_password($this->conn, $this->username, $this->password);
        }
    }

    // --------------------------------------------------------------------

    /**
     * Validates the connection ID
     *
     * @access	private
     * @return	bool
     */
    function _is_conn()
    {
        if ( ! is_resource($this->conn_sftp))
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_no_connection');
            }
            return FALSE;
        }
        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * Scans a directory from a given path
     *
     * @access	private
     * @return	array
     */

    function _scan_directory($dir, $recursive = FALSE)
    {
        $tempArray = array();
        $handle = opendir($dir);

        // List all the files
        while (false !== ($file = readdir($handle))) {
            if (substr("$file", 0, 1) != "."){
                if(is_dir($file) && $recursive){
                    // If its a directory, interate again
                    $tempArray[$file] = $this->_scan_directory("$dir/$file");
                } else {
                    $tempArray[] = $file;
                }
            }
        }

        closedir($handle);
        return $tempArray;
    }
    // --------------------------------------------------------------------


    /**
     * Create a directory
     *
     * @access	public
     * @param	string
     * @return	bool
     */
    function mkdir($path = '')
    {
        if ($path == '' OR ! $this->_is_conn())
        {
            return FALSE;
        }

        $result = @ssh2_sftp_mkdir($this->conn_sftp, $path);

        if ($result === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_makdir');
            }
            return FALSE;
        }

        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * Upload a file to the server
     *
     * @access	public
     * @param	string
     * @param	string
     * @return	bool
     */
    function upload($locpath, $rempath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        if ( ! file_exists($locpath))
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_no_source_file');
            }
            return FALSE;
        }

        $sftp = $this->conn_sftp;
        $stream = @fopen("ssh2.sftp://$sftp$rempath", 'w');

        if ($stream === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_upload');
            }
            return FALSE;
        }

        $data_to_send = @file_get_contents($locpath);

        if (@fwrite($stream, $data_to_send) === false)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_send_data');
            }
            return FALSE;
        }

        @fclose($stream);

        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * Download a file to the server
     *
     * @access	public
     * @param	string
     * @param	string
     * @return	bool
     */
    function download($rempath, $locpath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $sftp = $this->conn_sftp;

        $stream = @fopen("ssh2.sftp://$sftp$rempath", 'r');

        if ($stream === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_download');
            }
            return FALSE;
        }

        $contents = null;

        while (!feof($stream))
        {
            $contents .= @fread($stream, $this->buffer_size);
        }

        $result = file_put_contents($locpath, $contents);
        @fclose($stream);
        return $result;
    }

    // --------------------------------------------------------------------

    /**
     * Rename a file
     *
     * @access	public
     * @param	string
     * @param	string
     * @param	bool
     * @return	bool
     */
    function rename($old_file, $new_file, $move = FALSE)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $result = @ssh2_sftp_rename($this->conn_sftp, $old_file, $new_file);

        if ($result === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('ftp_unable_to_rename');
            }
            return FALSE;
        }

        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * Delete a file
     *
     * @access	public
     * @param	string
     * @return	bool
     */
    function delete_file($filepath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $sftp = $this->conn_sftp;
        $result = unlink("ssh2.sftp://$sftp$filepath");

        if ($result === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_delete');
            }
            return FALSE;
        }

        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * Delete a folder and recursively delete everything (including sub-folders)
     * containted within it.
     *
     * @access	public
     * @param	string
     * @return	bool
     */
    function delete_dir($filepath)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        // Add a trailing slash to the file path if needed
        $filepath = preg_replace("/(.+?)\/*$/", "\\1/",  $filepath);

        $result = @ssh2_sftp_rmdir($this->conn_id, $filepath);

        if ($result === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_delete');
            }
            return FALSE;
        }

        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * FTP List files in the specified directory
     *
     * @access	public
     * @param	string
     * @param	bool
     * @return	array
     */
    function list_files($path = '.', $recursive = FALSE)
    {
        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $sftp = $this->conn_sftp;
        $dir = "ssh2.sftp://$sftp$path";

        $directory = $this->_scan_directory($dir, $recursive);

        sort($directory);

        return $directory;
    }

    // ------------------------------------------------------------------------

    /**
     * Upload data from a variable
     *
     * @access	private
     * @param	string
     * @param	string
     * @return	bool
     */
    function upload_from_var($data_to_send, $rempath)
    {

        if ( ! $this->_is_conn())
        {
            return FALSE;
        }

        $sftp = $this->conn_sftp;

        $stream = @fopen("ssh2.sftp://$sftp$rempath", 'w');

        if ($stream === FALSE)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_upload');
            }
            return FALSE;
        }

        if (@fwrite($stream, $data_to_send) === false)
        {
            if ($this->debug == TRUE)
            {
                $this->_error('sftp_unable_to_send_data');
            }
            return FALSE;
        }

        @fclose($stream);

        return TRUE;
    }

    // ------------------------------------------------------------------------

    /**
     * Display error message and log them
     *
     * @access	private
     * @param	string
     * @return	void
     */
    function _error($line)
    {
        $this->logger->addError($line);
        $this->errors[] = $line;
    }

}
<?php
/**
 * SMF Class.
 *
 * This class is used to interact with a Simple Machines Forum installation
 *
 * @package classes
 * @copyright Copyright 2012-2013, Vinos de Frutas Tropicales (lat9): SMF Notifier-Hook Integration v1.0.0
 * @copyright Copyright 2003-2009 Zen Cart Development Team
 */

if (!defined('IS_ADMIN_FLAG')) {
  die('Illegal Access');
}

define('FILENAME_SMF_INDEX', 'index.php');
define('SMF_BB_NAME', 'SMF'); // The "name" of this Bulletin-board interface, used in duplicate email/nick error messages

if (!defined('SMF_DEBUG')) define('SMF_DEBUG', 'false');                 // Either 'true' or 'false'
if (!defined('SMF_DEBUG_MODE')) define('SMF_DEBUG_MODE', 'screen');      // Either 'screen', 'notify' or 'variable'
if (!defined('SMF_DEBUG_IP')) define('SMF_DEBUG_IP', '1');

class smf_observer extends base {
    var $debug;
    var $debug_info;      // Accumulates debug information if SMF_DEBUG_MODE is set to 'variable'
    var $installed;       // Indicates whether or not this module is installed/usable
    var $db_connect;      // Indicates whether or not the SMF database has been connected
    var $db_installed;    // Indicates whether or not all the required database tables are available
    var $files_installed;
    var $config_ok;       // Indicates whether or not the SMF database configuration is properly set
    var $bb_version;      // Fixed-up base bb version (v1.0.0 error) v1.1.0a

    var $smf_path;        // The file-system path to the SMF installation
    var $dir_smf;
    var $smf_url;    
    
    var $db_smf;          // Contains the SMF database object
    var $config;          // Array of values from the SMF /Settings.php file, set by get_smf_info
    var $settings_table;  // The name, with db_prefix, of the 'settings' table.
    var $members_table;   // The name, with db_prefix, of the 'members' table.
    
    const STATUS_DUPLICATE_NICK  = 'duplicate_nick';
    const STATUS_DUPLICATE_EMAIL = 'duplicate_email';
    const STATUS_MISSING_INPUTS  = 'missing_inputs';
    const STATUS_UNKNOWN_ERROR   = 'unknown_error';
    const STATUS_NICK_NOT_FOUND  = 'nick_not_found';
    
  function __construct () {
    $this->installed = false;
    $this->debug_info = array();
    $this->debug = (defined('SMF_DEBUG') && SMF_DEBUG == 'true') ? true : false;
    $this->attach($this, array('ZEN_BB_INSTANTIATE', 'ZEN_BB_CREATE_ACCOUNT', 'ZEN_BB_CHECK_NICK_NOT_USED', 'ZEN_BB_CHECK_EMAIL_NOT_USED', 'ZEN_BB_CHANGE_PASSWORD', 'ZEN_BB_CHANGE_EMAIL'));
  }
  
  function update(&$class, $eventID, $paramsArray) {
    if (!$this->installed) {
      if ($eventID == 'ZEN_BB_INSTANTIATE') {
        $this->debug_output('SMF_INSTANTIATE: zc_bb version = ' . $class->get_version());
//-bof-a-v1.1.0
        $this->bb_version = $class->get_version();
        if ($this->bb_version === 'BB_VERSION_MAJOR.BB_VERSION_MINOR') {
          $this->bb_version = '1.0.0';
        }
//-eof-a-v1.1.0

        if ($this->bb_version == 'BB_VERSION_MAJOR.BB_VERSION_MINOR') { /*v1.1.0c*/
          $this->debug_output('SMF_INSTANTIATE_FAILED. zc_bb Interface v1.1.0 or later required.');
          
        } elseif ($class->is_enabled()) {
          $this->debug_output('SMF_INSTANTIATE_FAILED. Another bulletin board (' . $class->get_bb_name() . ') is already attached.');
          
        } else {
          $this->smf_instantiate();
          if ($this->installed) {
            $class->set_bb_name(SMF_BB_NAME);
            $class->set_bb_url($this->smf_url);
            $class->set_enabled();
          }
        }
      }
    } else {
      switch ($eventID) {
        case 'ZEN_BB_CREATE_ACCOUNT': {
          $status = $this->smf_create_account($paramsArray['nick'], $paramsArray['pwd'], $paramsArray['email']);
          break;
        }
        case 'ZEN_BB_CHECK_NICK_NOT_USED': {
          $status = $this->smf_check_nick_not_used($paramsArray['nick']);
          break;
        }
        case 'ZEN_BB_CHECK_EMAIL_NOT_USED': {
          $status = $this->smf_check_email_not_used($paramsArray['email'], $paramsArray['nick']);
          break;
        }
        case 'ZEN_BB_CHANGE_PASSWORD': {
          $status = $this->smf_change_password($paramsArray['nick'], $paramsArray['pwd']);
          break;
        }
        case 'ZEN_BB_CHANGE_EMAIL': {
          $status = $this->smf_change_email($paramsArray['nick'], $paramsArray['email']);
          break;
        }
        default: {
          $status = self::STATUS_UNKNOWN_ERROR;
          break;
        }
      }
      
      $class->error_status  = $status;
      $class->return_status = ($status == bb::STATUS_OK) ? bb::STATUS_OK : bb::STATUS_ERROR;
      
    }
  }
  
  function smf_instantiate() {
    $this->db_connect = false;
    $this->db_installed = false;
    $this->files_installed = false;
    $this->config_ok = false;
    $this->installed = false;
    $this->smf_url = '';

    /////
    // If disabled in the Zen Cart admin, we're finished -- the module is not installed.
    //
    if ($this->is_enabled_in_zen_database()) {
      $this->get_smf_info();

      $this->db_smf = new queryFactory();
      $this->db_connect = $this->db_smf->connect($this->config['db_server'], $this->config['db_user'], $this->config['db_passwd'], $this->config['db_name'], USE_PCONNECT, false);
      
      if (!($this->db_connect)) {
        $this->debug_output('Failure: Could not connect to SMF database ( ' . $this->db_smf->error_number . ': ' . $this->db_smf->error_text . ').');
        
      } else {
        $this->db_installed = true;
        $this->config_ok = true;
        if ($this->get_smf_config_value('allow_editDisplayName') !== '0') {
          $this->config_ok = false;
          $this->debug_output('SMF connection disabled; set "Configuration->General->Allow users to edit their display name" to "unchecked"');
        }
      }
      
     //calculate the path from root of server for absolute path info
      $script_filename = $_SERVER['PATH_TRANSLATED'];
      if (empty($script_filename)) $script_filename = $_SERVER['SCRIPT_FILENAME'];
      $script_filename = str_replace(array('\\', '//'), '/', $script_filename);  //convert slashes

      if ($this->db_installed && $this->files_installed && $this->config_ok) {
        $this->smf_url = str_replace(array($_SERVER['DOCUMENT_ROOT'], substr($script_filename, 0, strpos($script_filename, $_SERVER['PHP_SELF']))), '', $this->smf_path) . FILENAME_SMF_INDEX;
        $this->installed = true;
        $this->debug_output('SMF Integration activated, SMF URL: ' . $this->smf_url);
      }
      
      if (!$this->installed) $this->debug_output('Failure: SMF NOT activated');
      
    }
    
    if (SMF_DEBUG_MODE == 'screen') { 
      $this->debug_output('YOU CAN IGNORE THE FOLLOWING "Cannot send session cache limited - headers already sent..." errors, as they are a result of the above debug output. A debug*.log file has been generated.');
    }

  }
  
  function is_enabled_in_zen_database() {
    $is_enabled = false;
    /////
    // If disabled in the plugin's configuration file, we're finished -- the module is not installed.  Otherwise, make sure
    // that each of the required fields (email address, nickname and password) have non-zero minimum lengths
    // set in the Zen Cart database.
    //
    if (SMF_LINKS_ENABLED != 'true') {
      $this->debug_output('SMF connection disabled; set \'SMF_LINKS_ENABLED\' to \'true\' in the file /includes/extra_configures/configure_smf.php.');
      
    } elseif (((int)ENTRY_EMAIL_ADDRESS_MIN_LENGTH) < 1) {
      $this->debug_output('SMF connection disabled; set "Minimum Values->E-Mail Address" to a value > 0.');
      
    } elseif ($this->bb_version < '1.2.0' && ((int)ENTRY_NICK_MIN_LENGTH) < 1) {  /*v1.1.0c*/
      $this->debug_output('SMF connection disabled; set "Minimum Values->Nick Name" to a value > 0.');
      
    } elseif (((int)ENTRY_PASSWORD_MIN_LENGTH) < 1) {
      $this->debug_output('SMF connection disabled; set "Minimum Values->Password" to a value > 0.');
      
    } else {
      $is_enabled = true;
      
    }
    
    return $is_enabled;
  }
  
  function get_smf_info() {
    $this->smf_path = '';
    $this->config = array();

    $this->dir_smf = str_replace(array('\\', '//'), '/', DIR_WS_SMF ); // convert slashes

    if (substr($this->dir_smf,-1) != '/') $this->dir_smf .= '/'; // ensure has a trailing slash
    $this->debug_output('SMF directory = ' . $this->dir_smf);

    //check if file exists
    if ($_SERVER['HTTP_HOST'] == 'localhost' && @file_exists($this->dir_smf . 'Settings_local.php')) {
      $config_file = 'Settings_local.php';
    } else {
      $config_file = 'Settings.php';
    }

    if (!(@file_exists($this->dir_smf . $config_file))) {
      $this->debug_output('Failure: ' . $this->dir_smf . $config_file . ' does not exist.');
      
    } else {
      // if exists, also store it for future use
      $this->smf_path = $this->dir_smf;
      $this->debug_output('Found SMF configuration file: ' . $this->dir_smf . $config_file);

      //----
      // Find smf database settings without including file:
      $lines = @file($this->smf_path . $config_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if ($lines === false) {
        $this->debug_output('Failure: Error reading ' . $this->dir_smf . $config_file);
        
      } else {
        $define_list = array('db_server', 'db_name', 'db_user', 'db_passwd', 'db_prefix', 'boardurl');
        $num_items = sizeof($define_list);
        $found_items = 0;
        foreach($lines as $line) { // read the config.php file for specific variables
          // First, strip all spaces, tabs, and single- and double-quotes
          $line = str_replace(array(' ', "'", "\t", '"'), '', $line);

          if (strpos($line, '$') === 0) {
            $current_var = explode('=', substr($line, 1, strpos($line, ';', 2) - 1));
            $this->debug_output('Processing variable: ' . print_r($current_var, true));
            if (in_array($current_var[0], $define_list)) {
              $varname = strtolower($current_var[0]);
              $this->config[$varname] = $current_var[1];
              $found_items++;
            }
          }
        }
        
        if ($num_items == $found_items) {
          $this->files_installed = true;
          $this->settings_table = $this->config['db_prefix'] . 'settings';
          $this->members_table = $this->config['db_prefix'] . 'members';

        }  // Found all Settings.php required items
        
        $this->debug_output('Finished processing SMF configuration:<br />' . print_r($this->smf, true));
        
      }  // Settings.php file successfully read
    }  // Settings.php file exists
  }  // END function get_smf_info

  function table_exists($table_name) {
    $found_table = false;
  // Check to see if the requested SMF table exists
    $sql = "SHOW TABLES like '$table_name'";
    $tables = $this->db_smf->Execute($sql);
    $this->debug_output("table_exists($table_name): " . print_r($tables, true));
    if ($tables->RecordCount() > 0) {
      $found_table = true;
    }
    return $found_table;
  }
  
  function get_smf_config_value($fieldname) {
    $sql = "SELECT value FROM $this->settings_table WHERE variable = '$fieldname'";
    $config = $this->db_smf->Execute($sql);
    
    return ($config->EOF) ? false : $config->fields['value'];
  }

  function smf_create_account($nick, $password, $email_address) {
    if (!zen_not_null($password) || !zen_not_null($email_address) || !zen_not_null($nick)) {
      $status = self::STATUS_MISSING_INPUTS;
    } else {
      $status = $this->smf_check_email_not_used($email_address);
      if ($status == bb::STATUS_OK) {
        $status = $this->smf_check_nick_not_used($nick);
        if ($status == bb::STATUS_OK) {
          $sql = "INSERT INTO $this->members_table
                  (member_name, passwd, email_address, date_registered, pm_email_notify, id_theme, id_post_group, is_activated)
                  VALUES
                  ('" .  $nick . "', '" . sha1(strtolower($nick) . $password) . "', '" . $email_address . "', '" . time() ."', 1, 0, 4, 1)";
          $this->db_smf->Execute($sql);
          $memberID = $this->db_smf->insert_ID();
          $sql = " UPDATE $this->settings_table SET value = '{$memberID}' WHERE variable = 'latestMember'";
          $this->db_smf->Execute($sql);
          $sql = " UPDATE $this->settings_table SET value = '{$nick}' WHERE variable = 'latestRealName'";
          $this->db_smf->Execute($sql);
          $sql = " UPDATE $this->settings_table SET value = value + 1 WHERE variable = 'totalMembers'";
          $this->db_smf->Execute($sql);
          $sql = "UPDATE $this->settings_table SET value = " . time() . " WHERE variable = 'memberlist_updated'";
          $this->db_smf->Execute($sql);
        }
      }
    }
    return $status;
  }

  function smf_check_nick_not_used($nick) {
    if (!zen_not_null($nick) || $nick == '') {
      $status = self::STATUS_INVALID_INPUTS;
    } else {
      $sql = "SELECT * FROM $this->members_table WHERE member_name = '$nick'";
      $smf_users = $this->db_smf->Execute($sql);
      $status = ($smf_users->RecordCount() > 0 ) ? self::STATUS_DUPLICATE_NICK : bb::STATUS_OK;
    }
    return $status;
  }

  function smf_check_email_not_used($email_address, $nick='') {
    if (!zen_not_null($email_address) || $email_address == '') {
      $status = self::STATUS_INVALID_INPUTS;
    } else {
      $check_nick = ($nick == '') ? '' : " AND member_name != '" . $nick . "'";
      $sql = "SELECT * FROM " . $this->config['db_prefix'] . "members WHERE email_address = '" . $email_address . "'" . $check_nick;
      $smf_users = $this->db_smf->Execute($sql);
      $status = ($smf_users->RecordCount() > 0 ) ? self::STATUS_DUPLICATE_EMAIL : bb::STATUS_OK;
    }
    return $status;
  }

  function smf_change_password($nick, $newpassword) {
    if (!zen_not_null($nick) || $nick == '') {
      $status = self::STATUS_MISSING_INPUTS;
      
    } elseif ($this->smf_check_nick_not_used($nick) != self::STATUS_DUPLICATE_NICK) {
      $status = self::STATUS_NICK_NOT_FOUND;
      
    } else {
      $status = bb::STATUS_OK;
      $sql = "UPDATE $this->members_table SET passwd ='" . sha1(strtolower($nick) . $newpassword) . "' WHERE member_name = '$nick'";
      $this->db_smf->Execute($sql);
    }
    return $status;
  }

  function smf_change_email($nick, $email_address) {
    if (!zen_not_null($nick) || $nick == '' || !zen_not_null($email_address) || $email_address == '') {
      $status = self::STATUS_MISSING_INPUTS;
      
    } elseif ($this->smf_check_email_not_used($email_address) != bb::STATUS_OK) {
      $status = self::STATUS_DUPLICATE_EMAIL;
      
    } elseif ($this->smf_check_nick_not_used($nick) != self::STATUS_DUPLICATE_NICK) {
      $status = self::STATUS_NICK_NOT_FOUND;
      
    } else {
      $status = bb::STATUS_OK;
      $sql = "UPDATE $this->members_table SET email_address ='$email_address' WHERE member_name = '$nick'";
      $this->db_smf->Execute($sql);
    }
    return $status;
  }

  function debug_output($outputString) {
    if ($this->debug) {
      switch (SMF_DEBUG_MODE) {
        case 'notify': {
          $this->notify('SMF_OBSERVER_DEBUG', $outputString);
          break;
        }
        case 'variable': {
          $this->debug_info[] = $outputString;
          break;
        }
        default: {
          if (defined('SMF_DEBUG_IP') && (SMF_DEBUG_IP == '' || SMF_DEBUG_IP == $_SERVER['REMOTE_ADDR'] || strstr(EXCLUDE_ADMIN_IP_FOR_MAINTENANCE, $_SERVER['REMOTE_ADDR']))) {
            echo $outputString . '<br />';
          }
          break;
        }
      }
    }
  }

}
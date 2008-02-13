<?php
$INC_GlobalBackend_ndomy = TRUE;
##########################################################################
##                 NagVis - The Nagios Visualisation                    ##
##########################################################################
## GlobalBackend_ndomy.php Backend module to fetch the status from      ##
##                         Nagios NDO Mysql DB. All not special to one  ##
##                         Backend related things should removed here!  ##
##########################################################################
## Licenced under the terms and conditions of the GPL Licence,          ##
## please see attached "LICENCE" file                                   ##
##########################################################################

class GlobalBackendndomy {
	var $MAINCFG;
	var $LANG;
	var $CONN;
	var $backendId;
	var $dbName;
	var $dbUser;
	var $dbPass;
	var $dbHost;
	var $dbPrefix;
	var $dbInstanceName;
	var $dbInstanceId;
	
	/**
	 * Constructor
	 * Reads needed configuration paramters, connects to the Database
	 * and checks that Nagios is running
	 *
	 * @param	config $MAINCFG
	 * @param	String $backendId
	 * @author	Andreas Husch <downanup@nagios-wiki.de>
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function GlobalBackendndomy(&$MAINCFG,$backendId) {
		$this->MAINCFG = &$MAINCFG;
		$this->backendId = $backendId;
		
		$this->dbName = $this->MAINCFG->getValue('backend_'.$backendId, 'dbname');
		$this->dbUser = $this->MAINCFG->getValue('backend_'.$backendId, 'dbuser');
		$this->dbPass = $this->MAINCFG->getValue('backend_'.$backendId, 'dbpass');
		$this->dbHost = $this->MAINCFG->getValue('backend_'.$backendId, 'dbhost');
		$this->dbPort = $this->MAINCFG->getValue('backend_'.$backendId, 'dbport');
		$this->dbPrefix = $this->MAINCFG->getValue('backend_'.$backendId, 'dbprefix');
		$this->dbInstanceName = $this->MAINCFG->getValue('backend_'.$backendId, 'dbinstancename');
		
		// initialize a language object for later error messages which be given out as state output
		$this->LANG = new GlobalLanguage($this->MAINCFG,'backend:ndomy');
		
		if($this->checkMysqlSupport() && $this->connectDB() && $this->checkTablesExists()) {
			// Set the instanceId
			$this->dbInstanceId = $this->getInstanceId();
			
			// Do some checks to make sure that Nagios is running and the Data at the DB are ok
			$QUERYHANDLE = $this->mysqlQuery('SELECT is_currently_running, status_update_time FROM '.$this->dbPrefix.'programstatus WHERE instance_id='.$this->dbInstanceId);
			$nagiosstate = mysql_fetch_array($QUERYHANDLE);
			
			// Check that Nagios reports itself as running	
			if ($nagiosstate['is_currently_running'] != 1) {
				$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'backend:ndomy'));
				$FRONTEND->messageToUser('ERROR','nagiosNotRunning','BACKENDID~'.$this->backendId);
			}
			 
			// Be suspiciosly and check that the data at the db are not older that "maxTimeWithoutUpdate" too
			if($_SERVER['REQUEST_TIME'] - strtotime($nagiosstate['status_update_time']) > $this->MAINCFG->getValue('backend_'.$backendId, 'maxtimewithoutupdate')) {
				$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'backend:ndomy'));
				$FRONTEND->messageToUser('ERROR','nagiosDataNotUpToDate','BACKENDID~'.$this->backendId.',TIMEWITHOUTUPDATE~'.$this->MAINCFG->getValue('backend_'.$backendId, 'maxtimewithoutupdate'));
			}
		} else {
			return FALSE;
		}
		
		return TRUE;
	}
	
	/**
	 * PRIVATE Method checkTablesExists
	 *
	 * Checks if there are the wanted tables in the DB
	 *
	 * @return	Boolean
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function checkTablesExists() {
		if(mysql_num_rows($this->mysqlQuery('SHOW TABLES LIKE \''.$this->dbPrefix.'programstatus\'')) == 0) {
			$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'backend:ndomy'));
			$FRONTEND->messageToUser('ERROR','noTablesExists','BACKENDID~'.$this->backendId.',PREFIX~'.$this->dbPrefix);
			
			return FALSE;
		} else {
			return TRUE;	
		}
	}
	
	/**
	 * PRIVATE Method connectDB
	 *
	 * Connects to DB
	 *
	 * @return	Boolean
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function connectDB() {
		// don't want to see mysql errors from connecting - only want our error messages
		$oldLevel = error_reporting(0);
		
		$this->CONN = mysql_connect($this->dbHost.':'.$this->dbPort, $this->dbUser, $this->dbPass);
		$returnCode = mysql_select_db($this->dbName, $this->CONN);
		
		// set the old level of reporting back
		error_reporting($oldLevel);
		
		if(!$returnCode){
			$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'backend:ndomy'));
			$FRONTEND->messageToUser('ERROR','errorSelectingDb','BACKENDID~'.$this->backendId.',MYSQLERR~'.mysql_error());
			
			return FALSE;
		} else {
			return TRUE;
		}
	}
	
	/**
	 * PRIVATE Method checkMysqlSupport
	 *
	 * Checks if MySQL is supported in this PHP version
	 *
	 * @return	Boolean
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function checkMysqlSupport() {
		// Check availability of PHP MySQL
		if (!extension_loaded('mysql')) {
			dl('mysql.so');
			if (!extension_loaded('mysql')) {
				//Error Box
				$FRONTEND = new GlobalPage($this->MAINCFG,Array('languageRoot'=>'backend:ndomy'));
				$FRONTEND->messageToUser('ERROR','mysqlNotSupported','BACKENDID~'.$this->backendId);
				
				return FALSE;
			} else {
				return TRUE;
			}
		} else {
			return TRUE;	
		}
	}
	
	/**
	 * PRIVATE Method getInstanceId
	 *
	 * Returns the instanceId of the instanceName
	 *
	 * @return	String $ret
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getInstanceId() {
		$QUERYHANDLE = $this->mysqlQuery('SELECT instance_id FROM '.$this->dbPrefix.'instances WHERE instance_name=\''.$this->dbInstanceName.'\' LIMIT 1');
		$ret = mysql_fetch_array($QUERYHANDLE);
		
		return $ret['instance_id'];
	}
	
	/**
	 * PRIVATE Method mysqlQuery
	 *
	 * @param   String      MySQL Query
	 * @return	Handle      Query Handle
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function mysqlQuery($query) {
		$QUERYHANDLE = mysql_query($query, $this->CONN) or die(mysql_error());
		return $QUERYHANDLE;
	}

	/**
	 * PUBLIC Method getObjects
	 * 
	 * Return the objects configured at Nagios wich are matching the given pattern. 
	 * This is needed for WUI, e.g. to populate drop down lists.
	 *
	 * @param	string $type, string $name1Pattern, string $name2Pattern
	 * @return	array $ret
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 * @author	Andreas Husch <downanup@nagios-wiki.de>
	 */
	function getObjects($type,$name1Pattern='',$name2Pattern='') {
		$ret = Array();
		$filter = '';
		
		switch($type) {
			case 'host':
				$objectType = 1;
				
				if($name1Pattern != '') {
					$filter = ' name1=\''.$name1Pattern.'\' AND ';
				}
			break;
			case 'service':
				$objectType = 2;
				
				if($name1Pattern != '' && $name2Pattern != '') {
					$filter = ' name1=\''.$name1Pattern.'\' AND name2=\''.$name2Pattern.'\' AND ';
				} else if($name1Pattern != '') {
					$filter = ' name1=\''.$name1Pattern.'\' AND ';
				}
			break;
			case 'hostgroup':
				$objectType = 3;
				
				if($name1Pattern != '') {
					$filter = ' name1=\''.$name1Pattern.'\' AND ';
				}
			break;
			case 'servicegroup':
				$objectType = 4;
				
				if($name1Pattern != '') {
					$filter = ' name1=\''.$name1Pattern.'\' AND ';
				}
			break;
			default:
				return Array();
			break;
		}
		
		/**
		 * is_active default value is 0.
		 * When broker option is -1 or BROKER_RETENTION_DATA is activated the 
		 * current objects have is_active=1 and the deprecated object have 
		 * is_active=0. Workaround: Check if there is any is_active=1, then use the
		 * is_active filter.
		 *
		 * For details see:
		 * https://sourceforge.net/tracker/index.php?func=detail&aid=1839631&group_id=132019&atid=725179
		 * http://www.nagios-portal.de/forum/thread.php?postid=59971#post59971
		 */
		if($this->checkForIsActiveObjects()) {
			$isActiveFilter = ' is_active=1 AND';
		} else {
			$isActiveFilter = '';
		}
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT name1,name2 FROM '.$this->dbPrefix.'objects WHERE objecttype_id='.$objectType.' AND '.$filter.$isActiveFilter.' instance_id='.$this->dbInstanceId.' ORDER BY name1');
		while($data = mysql_fetch_array($QUERYHANDLE)) {
			$ret[] = Array('name1' => $data['name1'],'name2' => $data['name2']);
		}
		
		return $ret;
	}
	
	/**
	 * PRIVATE Method checkIsActiveObjects
	 *
	 * Checks if there are some object records with is_active=1
	 *
	 * @return	Boolean
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function checkForIsActiveObjects() {
		if(mysql_num_rows($this->mysqlQuery('SELECT object_id FROM '.$this->dbPrefix.'objects WHERE is_active=1')) > 0) {
			return TRUE;
		} else {
			return FALSE;	
		}
	}
	
	/**
	 * PRIVATE Method getHostAckByHostname
	 *
	 * Returns if a host state has been acknowledged. The method doesn't check
	 * if the host is in OK/DOWN, It only checks.the has_been_acknowledged flag.
	 *
	 * @param	string $hostName
	 * @return	bool $ack
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getHostAckByHostname($hostName) {
		$return = FALSE;
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT problem_has_been_acknowledged 
		FROM '.$this->dbPrefix.'objects AS o,'.$this->dbPrefix.'hoststatus AS h 
		WHERE (o.objecttype_id=1 AND o.name1 = binary \''.$hostName.'\' AND o.instance_id='.$this->dbInstanceId.') AND h.host_object_id=o.object_id LIMIT 1');
		
		$data = mysql_fetch_array($QUERYHANDLE);
		// It's unnessecary to check if the value is 0, everything not equal to 1 is FALSE
		if(isset($data['problem_has_been_acknowledged']) && $data['problem_has_been_acknowledged'] == '1') {
			return TRUE;
		} else {
			return FALSE;
		}
	}
	
	/**
	 * PUBLIC getHostState()
	 *
	 * Returns the Nagios state and aditional informations for the requested host
	 *
	 * @param		String	$hostName
	 * @param		Boolean	$onlyHardstates
	 * @return	array		$state
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getHostState($hostName,$onlyHardstates) {
		$arrReturn = Array();
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT 
			alias, display_name, address, 
			has_been_checked, 
			last_hard_state, 
			UNIX_TIMESTAMP(last_hard_state_change) AS last_hard_state_change, 
			UNIX_TIMESTAMP(last_state_change) AS last_state_change, 
			current_state, 
			output, 
			problem_has_been_acknowledged, 
			UNIX_TIMESTAMP(last_check) AS last_check, UNIX_TIMESTAMP(next_check) AS next_check, 
			hs.state_type, hs.current_check_attempt, hs.max_check_attempts, 
			UNIX_TIMESTAMP(dh.scheduled_start_time) AS downtime_start, UNIX_TIMESTAMP(dh.scheduled_end_time) AS downtime_start, 
			dh.author_name AS downtime_author, dh.comment_data AS downtime_data
		FROM 
			'.$this->dbPrefix.'hosts AS h, 
			'.$this->dbPrefix.'hoststatus AS hs, 
			'.$this->dbPrefix.'objects AS o 
		LEFT JOIN
			'.$this->dbPrefix.'downtimehistory AS dh
			ON dh.object_id=o.object_id AND NOW()>dh.scheduled_start_time AND NOW()<dh.scheduled_end_time
		WHERE 
			(o.objecttype_id=1 AND o.name1 = binary \''.$hostName.'\' AND o.instance_id='.$this->dbInstanceId.') 
			AND (h.config_type=1 AND h.instance_id='.$this->dbInstanceId.' AND h.host_object_id=o.object_id) 
			AND hs.host_object_id=o.object_id 
		LIMIT 1');
		
		if(mysql_num_rows($QUERYHANDLE) == 0) {
			$arrReturn['state'] = 'ERROR';
			$arrReturn['output'] = $this->LANG->getMessageText('hostNotFoundInDB','HOST~'.$hostName);
		} else {
			$data = mysql_fetch_array($QUERYHANDLE);
			
			$arrReturn['alias'] = $data['alias'];
			$arrReturn['display_name'] = $data['display_name'];
			$arrReturn['address'] = $data['address'];
			
			// Add Additional informations to array
			$arrReturn['last_check'] = $data['last_check'];
			$arrReturn['next_check'] = $data['next_check'];
			$arrReturn['state_type'] = $data['state_type'];
			$arrReturn['current_check_attempt'] = $data['current_check_attempt'];
			$arrReturn['max_check_attempts'] = $data['max_check_attempts'];
			$arrReturn['last_state_change'] = $data['last_state_change'];
			$arrReturn['last_hard_state_change'] = $data['last_hard_state_change'];
			
			// If there is a downtime for this object, save the data
			if(isset($data['downtime_start']) && $data['downtime_start'] != '') {
				$arrReturn['in_downtime'] = 1;
				$arrReturn['downtime_start'] = $data['downtime_start'];
				$arrReturn['downtime_end'] = $data['downtime_end'];
				$arrReturn['downtime_author'] = $data['downtime_author'];
				$arrReturn['downtime_data'] = $data['downtime_data'];
			}
			
			/**
			 * Only recognize hard states. There was a discussion about the implementation
			 * This is a new handling of only_hard_states. For more details, see: 
			 * http://www.nagios-portal.de/wbb/index.php?page=Thread&threadID=8524
			 *
			 * Thanks to Andurin and fredy82
			 */
			if($onlyHardstates == 1) {
				if($data['state_type'] != '0') {
					$data['current_state'] = $data['current_state'];
				} else {
					$data['current_state'] = $data['last_hard_state'];
				}
			}
			
			if($data['has_been_checked'] == '0') {
				$arrReturn['state'] = 'PENDING';
				$arrReturn['output'] = $this->LANG->getMessageText('hostIsPending','HOST~'.$hostName);
			} elseif($data['current_state'] == '0') {
				// Host is UP
				$arrReturn['state'] = 'UP';
				$arrReturn['output'] = $data['output'];
			} else {
				// Host is DOWN/UNREACHABLE/UNKNOWN
				
				// Store acknowledgement state in array
				$arrReturn['problem_has_been_acknowledged'] = $data['problem_has_been_acknowledged'];
				
				// Store state and output in array
				switch($data['current_state']) {
					case '1': 
						$arrReturn['state'] = 'DOWN';
						$arrReturn['output'] = $data['output'];
					break;
					case '2':
						$arrReturn['state'] = 'UNREACHABLE';
						$arrReturn['output'] = $data['output'];
					break;
					case '3':
						$arrReturn['state'] = 'UNKNOWN';
						$arrReturn['output'] = $data['output'];
					break;
					default:
						$arrReturn['state'] = 'UNKNOWN';
						$arrReturn['output'] = 'GlobalBackendndomy::getHostState: Undefined state!';
					break;
				}
			}
		}
		
		return $arrReturn;
	}
	
	/**
	 * PUBLIC getHostState()
	 *
	 * Returns the state and aditional informations of rhe requested service
	 *
	 * @param		String 	$hostName
	 * @param		String 	$serviceName
	 * @param		Boolean	$onlyHardstates
	 * @return	Array		$state
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getServiceState($hostName, $serviceName, $onlyHardstates) {
		$arrReturn = Array();
		
		if(isset($serviceName) && $serviceName != '') {
			$QUERYHANDLE = $this->mysqlQuery('SELECT 
				o.name1, o.name2, 
				s.display_name, 
				ss.has_been_checked, ss.last_hard_state, ss.current_state, 
				UNIX_TIMESTAMP(ss.last_hard_state_change) AS last_hard_state_change, 
				UNIX_TIMESTAMP(ss.last_state_change) AS last_state_change, 
				ss.output, ss.problem_has_been_acknowledged, 
				UNIX_TIMESTAMP(ss.last_check) AS last_check, UNIX_TIMESTAMP(ss.next_check) AS next_check, 
				ss.state_type, ss.current_check_attempt, ss.max_check_attempts,
				UNIX_TIMESTAMP(dh.scheduled_start_time) AS downtime_start, UNIX_TIMESTAMP(dh.scheduled_end_time) AS downtime_start, 
				dh.author_name AS downtime_author, dh.comment_data AS downtime_data
				FROM 
					'.$this->dbPrefix.'services AS s,
					'.$this->dbPrefix.'objects AS o
				LEFT JOIN
					'.$this->dbPrefix.'servicestatus AS ss
					ON ss.service_object_id=o.object_id
				LEFT JOIN
					'.$this->dbPrefix.'downtimehistory AS dh
					ON dh.object_id=o.object_id AND NOW()>dh.scheduled_start_time AND NOW()<dh.scheduled_end_time
				WHERE 
					(o.objecttype_id=2 AND o.name1 = binary \''.$hostName.'\' AND o.name2 = binary \''.$serviceName.'\' AND o.instance_id='.$this->dbInstanceId.')
					AND (s.config_type=1 AND s.instance_id='.$this->dbInstanceId.' AND s.service_object_id=o.object_id) 
					AND ss.service_object_id=o.object_id 
				LIMIT 1');
		} else {
			$QUERYHANDLE = $this->mysqlQuery('SELECT 
				o.name1, o.name2,
				s.display_name, 
				ss.has_been_checked, ss.last_hard_state, ss.current_state, 
				UNIX_TIMESTAMP(ss.last_hard_state_change) AS last_hard_state_change, 
				UNIX_TIMESTAMP(ss.last_state_change) AS last_state_change, 
				ss.output, ss.problem_has_been_acknowledged, 
				UNIX_TIMESTAMP(ss.last_check) AS last_check, UNIX_TIMESTAMP(ss.next_check) AS next_check, 
				ss.state_type, ss.current_check_attempt, ss.max_check_attempts,
				UNIX_TIMESTAMP(dh.scheduled_start_time) AS downtime_start, UNIX_TIMESTAMP(dh.scheduled_end_time) AS downtime_start, 
				dh.author_name AS downtime_author, dh.comment_data AS downtime_data
				FROM 
					'.$this->dbPrefix.'services AS s,
					'.$this->dbPrefix.'objects AS o
				LEFT JOIN
					'.$this->dbPrefix.'servicestatus AS ss
					ON ss.service_object_id=o.object_id
				LEFT JOIN
					'.$this->dbPrefix.'downtimehistory AS dh
					ON dh.object_id=o.object_id AND NOW()>dh.scheduled_start_time AND NOW()<dh.scheduled_end_time
				WHERE 
					(o.objecttype_id=2 AND o.name1 = binary \''.$hostName.'\' AND o.instance_id='.$this->dbInstanceId.') 
					AND (s.config_type=1 AND s.instance_id='.$this->dbInstanceId.' AND s.service_object_id=o.object_id) 
					');
		}
		
		if(mysql_num_rows($QUERYHANDLE) == 0) {
			if(isset($serviceName) && $serviceName != '') {
				$arrReturn['state'] = 'ERROR';
				$arrReturn['output'] = $this->LANG->getMessageText('serviceNotFoundInDB','SERVICE~'.$serviceName.',HOST~'.$hostName);
			} else {
				$arrReturn['error']['state'] = 'ERROR';
				$arrReturn['error']['output'] = $this->LANG->getMessageText('serviceNotFoundInDB','SERVICE~'.$serviceName.',HOST~'.$hostName);
			}
		} else {
			while($data = mysql_fetch_array($QUERYHANDLE)) {
				$arrTmpReturn = Array();
				
				$arrTmpReturn['service_description'] = $data['name2'];
				$arrTmpReturn['display_name'] = $data['display_name'];
				$arrTmpReturn['alias'] = $data['display_name'];
				
				// Add Additional informations to array
				$arrTmpReturn['last_check'] = $data['last_check'];
				$arrTmpReturn['next_check'] = $data['next_check'];
				$arrTmpReturn['state_type'] = $data['state_type'];
				$arrTmpReturn['current_check_attempt'] = $data['current_check_attempt'];
				$arrTmpReturn['max_check_attempts'] = $data['max_check_attempts'];
				$arrTmpReturn['last_state_change'] = $data['last_state_change'];
				$arrTmpReturn['last_hard_state_change'] = $data['last_hard_state_change'];
				
				// If there is a downtime for this object, save the data
				if(isset($data['downtime_start']) && $data['downtime_start'] != '') {
					$arrTmpReturn['in_downtime'] = 1;
					$arrTmpReturn['downtime_start'] = $data['downtime_start'];
					$arrTmpReturn['downtime_end'] = $data['downtime_end'];
					$arrTmpReturn['downtime_author'] = $data['downtime_author'];
					$arrTmpReturn['downtime_data'] = $data['downtime_data'];
				}
				
				/**
				 * Only recognize hard states. There was a discussion about the implementation
				 * This is a new handling of only_hard_states. For more details, see: 
				 * http://www.nagios-portal.de/wbb/index.php?page=Thread&threadID=8524
				 *
				 * Thanks to Andurin and fredy82
				 */
				if($onlyHardstates == 1) {
					if($data['state_type'] != '0') {
						$data['current_state'] = $data['current_state'];
					} else {
						$data['current_state'] = $data['last_hard_state'];
					}
				}
				
				if($data['has_been_checked'] == '0') {
					$arrTmpReturn['state'] = 'PENDING';
					$arrTmpReturn['output'] = $this->LANG->getMessageText('serviceNotChecked','SERVICE~'.$data['name2']);
				} elseif($data['current_state'] == '0') {
					// Host is UP
					$arrTmpReturn['state'] = 'OK';
					$arrTmpReturn['output'] = $data['output'];
				} else {
					// Host is DOWN/UNREACHABLE/UNKNOWN
					
					/**
					 * If state is not OK (=> WARN, CRIT, UNKNOWN) and service is not 
					 * acknowledged => check for acknowledged host
					 */
					if($data['problem_has_been_acknowledged'] != 1) {
						$arrTmpReturn['problem_has_been_acknowledged'] = $this->getHostAckByHostname($hostName);
					} else {
						$arrTmpReturn['problem_has_been_acknowledged'] = $data['problem_has_been_acknowledged'];
					}
					
					// Store state and output in array
					switch($data['current_state']) {
						case '1': 
							$arrTmpReturn['state'] = 'WARNING';
							$arrTmpReturn['output'] = $data['output'];
						break;
						case '2':
							$arrTmpReturn['state'] = 'CRITICAL';
							$arrTmpReturn['output'] = $data['output'];
						break;
						case '3':
							$arrTmpReturn['state'] = 'UNKNOWN';
							$arrTmpReturn['output'] = $data['output'];
						break;
						default:
							$arrTmpReturn['state'] = 'UNKNOWN';
							$arrTmpReturn['output'] = 'GlobalBackendndomy::getServiceState: Undefined state!';
						break;
					}
				}
				
				// If more than one services are expected, append the current return informations to return array
				if(isset($serviceName) && $serviceName != '') {
					$arrReturn = $arrTmpReturn;
				} else {
					// Assign actual dataset to return array
					$arrReturn[strtr($data['name2'],' ','_')] = $arrTmpReturn;
				}
			}
		}
		return $arrReturn;
	}
	
	/**
	 * PUBLIC Method getHostNamesWithNoParent
	 *
	 * Gets all hosts with no parent host. This method is needed by the automap 
	 * to get the root host.
	 *
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getHostNamesWithNoParent() {
		$arrReturn = Array();
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT o1.name1
		FROM
		`'.$this->dbPrefix.'objects` AS o1,
		`'.$this->dbPrefix.'hosts` AS h1
		LEFT OUTER JOIN `nagios_host_parenthosts` AS ph1 ON h1.host_id=ph1.host_id
		WHERE o1.objecttype_id=1
		AND (h1.config_type=1 AND h1.instance_id='.$this->dbInstanceId.' AND h1.host_object_id=o1.object_id) 
		AND ph1.parent_host_object_id IS null');
		
		while($data = mysql_fetch_array($QUERYHANDLE)) {
			$arrReturn[] = $data['name1'];
		}
		
		return $arrReturn;
	}
	
	/**
	 * PUBLIC Method getDirectChildNamesByHostName
	 *
	 * Gets the names of all child hosts
	 *
	 * @param		String		Name of host to get the childs of
	 * @return	Array			Array with hostnames
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getDirectChildNamesByHostName($hostName) {
		$arrChildNames = Array();
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT o2.name1
		FROM
		`'.$this->dbPrefix.'objects` AS o1,
		`'.$this->dbPrefix.'hosts` AS h1,
		`'.$this->dbPrefix.'host_parenthosts` AS ph1,
		`'.$this->dbPrefix.'hosts` AS h2,
		`'.$this->dbPrefix.'objects` AS o2
		WHERE o1.objecttype_id=1 AND o1.name1=\''.$hostName.'\'
		AND (h1.config_type=1 AND h1.instance_id='.$this->dbInstanceId.' AND h1.host_object_id=o1.object_id)
		AND o1.object_id=ph1.parent_host_object_id
		AND (h2.config_type=1 AND h2.instance_id='.$this->dbInstanceId.' AND h2.host_id=ph1.host_id)
		AND o2.objecttype_id=1 AND h2.host_object_id=o2.object_id');
		while($data = mysql_fetch_array($QUERYHANDLE)) {
			$arrChildNames[] = $data['name1'];
		}
		
		return $arrChildNames;
	}
	
	/**
	 * PUBLIC Method getServicesByHostName
	 *
	 * Gets all services of a given host
	 *
	 * @param		String		Name of host to get the services of
	 * @return	Array			Array with service descriptions
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getServicesByHostName($hostName) {
		$arrReturn = Array();
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT 
				o.name2
			FROM 
				'.$this->dbPrefix.'objects AS o, 
				'.$this->dbPrefix.'services AS s, 
				'.$this->dbPrefix.'servicestatus AS ss 
			WHERE 
				(o.objecttype_id=2 AND o.name1 = binary \''.$hostName.'\' AND o.instance_id='.$this->dbInstanceId.') 
				AND (s.config_type=1 AND s.instance_id='.$this->dbInstanceId.' AND s.service_object_id=o.object_id) 
				AND ss.service_object_id=o.object_id');
		
		while($data = mysql_fetch_array($QUERYHANDLE)) {
			// Assign actual dataset to return array
			$arrReturn[] = $data['name2'];
		}
		
		return $arrReturn;
	}
	
	/**
	 * PUBLIC Method getHostsByHostgroupName
	 *
	 * Gets all hosts of a hostgroup
	 *
	 * @param		String		Name of hostgroup to get the hosts of
	 * @return	Array			Array with hostnames
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getHostsByHostgroupName($hostgroupName) {
		$arrReturn = Array();
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT 
				o2.name1
			FROM 
				'.$this->dbPrefix.'objects AS o,
				'.$this->dbPrefix.'hostgroups AS hg,
				'.$this->dbPrefix.'hostgroup_members AS hgm,
				'.$this->dbPrefix.'objects AS o2
			WHERE 
				(o.objecttype_id=3 AND o.name1 = binary \''.$hostgroupName.'\' AND o.instance_id='.$this->dbInstanceId.') 
				AND (hg.config_type=1 AND hg.instance_id='.$this->dbInstanceId.' AND hg.hostgroup_object_id=o.object_id) 
				AND hgm.hostgroup_id=hg.hostgroup_id 
				AND (o2.objecttype_id=1 AND o2.object_id=hgm.host_object_id)');
		
		while($data = mysql_fetch_array($QUERYHANDLE)) {
			// Assign actual dataset to return array
			$arrReturn[] = $data['name1'];
		}
		
		return $arrReturn;
	}
	
	/**
	 * PUBLIC Method getServicesByServicegroupName
	 *
	 * Gets all services of a servicegroup
	 *
	 * @param		String		Name of servicegroup to get the services of
	 * @return	Array			Array with hostnames and service descriptions
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getServicesByServicegroupName($servicegroupName) {
		$arrReturn = Array();
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT 
				o2.name1, o2.name2 
			FROM 
				'.$this->dbPrefix.'objects AS o,
				'.$this->dbPrefix.'servicegroups AS sg,
				'.$this->dbPrefix.'servicegroup_members AS sgm,
				'.$this->dbPrefix.'objects AS o2
			WHERE 
				(o.objecttype_id=4 AND o.name1 = binary \''.$servicegroupName.'\' AND o.instance_id='.$this->dbInstanceId.') 
				AND (sg.config_type=1 AND sg.instance_id='.$this->dbInstanceId.' AND sg.servicegroup_object_id=o.object_id) 
				AND sgm.servicegroup_id=sg.servicegroup_id 
				AND (o2.objecttype_id=2 AND o2.object_id=sgm.service_object_id)');
	
		while($data = mysql_fetch_array($QUERYHANDLE)) {
			// Assign actual dataset to return array
			$arrReturn[] = Array('host_name' => $data['name1'], 'service_description' => $data['name2']);
		}
		
		return $arrReturn;
	}
	
	/**
	 * PUBLIC Method getNagiosStartTime
	 *
	 * Gets the last start/reload/restart time of NagVis as UNIX timestamp
	 *
	 * @return	Integer		Timestamp of Nagios start time
	 * @author	Lars Michelsen <lars@vertical-visions.de>
	 */
	function getNagiosStartTime() {
		
		$QUERYHANDLE = $this->mysqlQuery('SELECT 
				UNIX_TIMESTAMP(program_start_time) AS program_start_time 
			FROM 
				'.$this->dbPrefix.'programstatus
			LIMIT 1');
		
		$data = mysql_fetch_array($QUERYHANDLE);
		
		return $data['program_start_time'];
	}
}
?>

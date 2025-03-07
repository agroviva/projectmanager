<?php
/**
 * ProjectManager - General business object
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package projectmanager
 * @copyright (c) 2005-19 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

use EGroupware\Api;
use EGroupware\Api\Link;
use EGroupware\Api\Acl;

define('EGW_ACL_BUDGET',Acl::CUSTOM1);
define('EGW_ACL_EDIT_BUDGET',Acl::CUSTOM2);
define('EGW_ACL_ADD_TIMESHEET', Acl::CUSTOM3);

/**
 * General business object of the projectmanager
 *
 * This class does all the timezone-conversation: All function expect user-time and convert them to server-time
 * before calling the storage object.
 */
class projectmanager_bo extends projectmanager_so
{
	/**
	 * Debuglevel: 0 = no debug-messages, 1 = main, 2 = more, 3 = all, 4 = all incl. Api\Storage\Base, or string with function-name to debug
	 *
	 * @var int|string
	 */
	var $debug=false;
	/**
	 * File to log debug-messages, ''=echo them, 'error_log' to use error_log()
	 *
	 * @var string
	 */
	static $logfile='error_log';	// '/tmp/pm.log';
	/**
	 * Timestaps that need to be adjusted to user-time on reading or saving
	 *
	 * @var array
	 */
	var $timestamps = array(
		'pm_created','pm_modified','pm_planned_start','pm_planned_end','pm_real_start','pm_real_end',
	);
	/**
	 * Offset in secconds between user and server-time,	it need to be add to a server-time to get the user-time
	 * or substracted from a user-time to get the server-time
	 *
	 * @var int
	 */
	var $tz_offset_s;
	/**
	 * Current time as timestamp in user-time
	 *
	 * @var int
	 */
	var $now_su;
	/**
	 * Instance of the soconstraints-class
	 *
	 * @var soconstraints
	 */
	var $constraints;
	/**
	 * Instance of the somilestones-class
	 *
	 * @var somilestones
	 */
	var $milestones;
	/**
	 * Instance of the soroles-class, not instanciated automatic!
	 *
	 * @var soroles
	 */
	var $roles;
	/**
	 * Atm. projectmanager-admins are identical to eGW admins, this might change in the future
	 *
	 * @var boolean
	 */
	var $is_admin;
	/**
	 * Instance of the timesheet_tracking object
	 *
	 * @var timesheet_tracking
	 */
	var $historylog;
	/**
	 * Translates field / acl-names to labels
	 *
	 * @var array
	 */
	var $field2label = array(
		'pm_id'		         => 'Projectid',
		'pm_title'     	     => 'Title',
		'pm_number'    	     => 'Projectnumber',
		'pm_description'     => 'Description',
		'pm_creator'         => 'Owner',
		'pm_created'    	 => 'Created',
		'pm_modifier' 		 => 'Modifier',
		'pm_modified'    	 => 'Modified',
		'pm_planned_start'   => 'Planned start',
		'pm_planned_end'     => 'Planned end',
		'pm_real_start'      => 'Real start',
		'pm_real_end'        => 'Real end',
		'cat_id'             => 'Category',
		'pm_access'          => 'Access',
		'pm_priority'        => 'Priority',
		'pm_status'          => 'Status',
		'pm_completion'      => 'Completion',
		'pm_used_time'       => 'Used time',
		'pm_planned_time'    => 'Planned time',
		'pm_replanned_time'  => 'Replanned time',
		'pm_used_budget'     => 'Used budget',
		'pm_planned_budget'  => 'Planned budget',
		'pm_overwrite'       => 'Overwrite',
	    'pm_accounting_type' => 'Accounting type',
		// pseudo fields used in edit
		//'link_to'        => 'Attachments & Links',
		'#c'   => 'Custom fields',	// only used to display old history, new cf history is stored field by field
	);

	/**
	 * History logging: ''=no, 'history'=history & delete allowed, 'history_admin_delete', 'history_no_delete'
	 *
	 * @var string
	 */
	var $history = '';

	const DELETED_STATUS = 'deleted';

	/**
	 * Names of all config vars
	 *
	 * @var array
	 */
	var $tracking;
	/**
	 * User preferences
	 *
	 * @var array
	 */
	var $prefs;

	/**
	 * Constructor, calls the constructor of the extended class
	 *
	 * @param int $pm_id id of the project to load, default null
	 * @param string $instanciate ='' comma-separated: constraints,milestones,roles
	 * @return projectmanager_bo
	 */
	function __construct($pm_id=null,$instanciate='')
	{
		if ((int) $this->debug >= 3 || $this->debug == 'projectmanager') $this->debug_message(function_backtrace()."\nprojectmanager_bo::projectmanager_bo($pm_id) started");

		$this->tz_offset_s = $GLOBALS['egw']->datetime->tz_offset;
		$this->now_su = Api\DateTime::server2user('now','ts');

		$this->prefs =& $GLOBALS['egw_info']['user']['preferences']['projectmanager'];

		parent::__construct($pm_id);

		// save us in $GLOBALS['boprojectselements'] for ExecMethod used in hooks
		if (!is_object($GLOBALS['projectmanager_bo']))
		{
			$GLOBALS['projectmanager_bo'] =& $this;
		}
		// atm. projectmanager-admins are identical to eGW admins, this might change in the future
		$this->is_admin = isset($GLOBALS['egw_info']['user']['apps']['admin']);

		// Keep deleted projects?
		$this->history = $this->config['history'];

		if ($instanciate) $this->instanciate($instanciate);

		if ((int) $this->debug >= 3 || $this->debug == 'projectmanager') $this->debug_message("projectmanager_bo::projectmanager_bo($pm_id) finished");
	}

	/**
	 * Instanciates some classes which dont get instanciated by default
	 *
	 * @param string $instanciate comma-separated: constraints,milestones,roles
	 * @param string $pre ='so' class prefix to use, default so
	 */
	function instanciate($instanciate,$pre='so')
	{
		foreach(explode(',',$instanciate) as $class)
		{
			if (!is_object($this->$class))
			{
				$cname = 'projectmanager_'.$class.'_'.$pre;
				$this->$class = new $cname();
			}
		}
	}

	/**
	 * Summarize the information of all elements of a project: min(start-time), sum(time), avg(completion), ...
	 *
	 * This is implemented in the projectelements class, we call it via ExecMethod
	 *
	 * @param int|array $pm_id =null int project-id, array of project-id's or null to use $this->pm_id
	 * @return array|boolean with summary information (keys as for a single project-element), false on error
	 */
	function pe_summary($pm_id=null)
	{
		if (is_null($pm_id)) $pm_id = $this->data['pm_id'];

		if (!$pm_id) return array();

		return ExecMethod('projectmanager.projectmanager_elements_bo.summary',$pm_id);
	}

	/**
	 * update a project after a change in one of it's project-elements
	 *
	 * If the data and the exact changes gets supplied (see params),
	 * an whole update or even the update itself might be avoided.
	 * Not used at the moment!
	 *
	 * @param int $pm_id =null project-id or null to use $this->data['pm_id']
	 * @param int $update_necessary =-1 which fields need updating, or'ed PM_ constants from the datasource class
	 * @param array $data =null data of the project-element if availible, currently not used
	 */
	function update($pm_id=null,$update_necessary=-1,$data=null)
	{
		unset($data);	// not used
		//error_log(__METHOD__."($pm_id, $update_necessary, ".array2string($data).")");
		if (!$pm_id)
		{
			$pm_id = $this->data['pm_id'];
		}
		elseif ($pm_id != $this->data['pm_id'])
		{
			// we need to restore it later
			$save_data = $this->data;

			if (!$this->read(array('pm_id' => $pm_id))) return;	// project does (no longer) exist
		}
		$pe_summary = $this->pe_summary($pm_id);
		//error_log(__METHOD__."() pe_summary($pm_id) = ".array2string($pe_summary));

		if ((int) $this->debug >= 2 || $this->debug == 'update') $this->debug_message("projectmanager_bo::update($pm_id,$update_necessary) pe_summary=".print_r($pe_summary,true));

		if (!$this->pe_name2id)
		{
			// we need the PM_ id's
			include_once(EGW_INCLUDE_ROOT.'/projectmanager/inc/class.datasource.inc.php');

			$ds = new datasource();
			$this->pe_name2id = $ds->name2id;
			unset($ds);
		}
		$save_necessary = false;
		foreach($this->pe_name2id as $name => $id)
		{
			if ($id == PM_CAT_ID) continue;	// do NOT set category from elements

			$pm_name = str_replace('pe_','pm_',$name);
			if (!($this->data['pm_overwrite'] & $id) && $this->data[$pm_name] != $pe_summary[$name])
			{
				$this->data[$pm_name] = $pe_summary[$name];
				$save_necessary = true;
			}
		}
		if ($save_necessary)
		{
			$this->save(null, false,	// dont touch modification date
				true, true);	// do not send notification emails, they wont work in shutdown callback and NOT wanted for PE updates
		}
		// restore $this->data
		if (is_array($save_data) && $save_data['pm_id'])
		{
			$this->data = $save_data;
		}
	}

	/**
	 * saves a project
	 *
	 * reimplemented to automatic create a project-ID / pm_number, if empty
	 *
	 * @param array $keys if given $keys are copied to data before saveing => allows a save as
	 * @param boolean $touch_modified =true should modification date+user be set, default yes
	 * @param boolean $do_notify =true should link::notify be called, default yes
	 * @param boolean $skip_notification =false should notification(-email) be skiped
	 * @return int 0 on success and errno != 0 else
	 */
	function save($keys=null, $touch_modified=true, $do_notify=true, $skip_notification=false)
	{
		//error_log(__METHOD__."(".array2string($keys).", touch_modified=$touch_modified, do_notify=$do_notify)");
		if ($keys) $this->data_merge($keys);

		// check if we have a project-ID and generate one if not
		if (empty($this->data['pm_number']))
		{
			$this->generate_pm_number();
		}
		// set creation and modification data
		if (!$this->data['pm_id'])
		{
			$this->data['pm_creator'] = $GLOBALS['egw_info']['user']['account_id'];
			$this->data['pm_created'] = $this->now_su;
		}
		$check_modified = $this->data['pm_modified'] - $this->tz_offset_s;
		if ($touch_modified)
		{
			$this->data['pm_modifier'] = $GLOBALS['egw_info']['user']['account_id'];
			$this->data['pm_modified'] = $this->now_su;
		}
		if ((int) $this->debug >= 1 || $this->debug == 'save') $this->debug_message("projectmanager_bo::save(".print_r($keys,true).",".(int)$touch_modified.") data=".print_r($this->data,true));

		// check if we have a real modification
		// read the old record needed for history logging
		$new =& $this->data;
		unset($this->data);
		$this->read($new['pm_id']);
		$old =& $this->data;
		$this->data =& $new;
		if (!($err = parent::save(null, $check_modified)) && $do_notify)
		{
			$extra = array();
			if ($old && $this->link_title($new) !== ($old_title=$this->link_title($old)))
			{
				$extra[Link::OLD_LINK_TITLE] = $old_title;
			}
			// Check for restore of deleted entry, restore held links
			if($old['pm_status'] == self::DELETED_STATUS && $new['pm_status'] != self::DELETED_STATUS)
			{
				Link::restore('projectmanager', $this->data['pm_id']);
			}
			// notify the link-class about the update, as other apps may be subscribt to it
			//error_log(__METHOD__."() calling Link::notify_update('projectmanager', {$this->data['pm_id']}, ".array2string($this->data+$extra).")");
			Link::notify_update('projectmanager',$this->data['pm_id'],$this->data+$extra);
		}
		//$changed[] = array();
		if (isset($old)) foreach($old as $name => $value)
		{
			if (isset($new[$name]) && $new[$name] != $value)
			{
				$changed[$name] = $name;
				if ($name =='pm_completion' && $new['pm_completion'].'%' == $value) unset($changed[$name]);
				if ($name =='pm_modified') unset($changed[$name]);
				if ($name =='pm_members') unset($changed[$name]);
			}
		}
		if (!$changed && $old['pm_id']!='')
		{
			return false;
		}
		if (!is_object($this->tracking))
		{
			$this->tracking = new projectmanager_tracking($this);
			$this->tracking->html_content_allow = true;
		}
		if (!$this->tracking->track($this->data, $old, $this->user, null, null, $skip_notification))
		{
			return implode(', ',$this->tracking->errors);
		}
		return $err;
	}

	/**
	 * deletes a project identified by $keys or the loaded one, reimplemented to remove the project-elements too
	 *
	 * @param array $keys if given array with col => value pairs to characterise the rows to delete
	 * @param boolean $delete_sources =false true=delete datasources of the elements too (if supported by the datasource), false dont do it
	 * @param boolean $skip_notification Do not send notification of delete
	 *
	 * @return int affected rows, should be 1 if ok, 0 if an error
	 */
	function delete($keys=null,$delete_sources=false, $skip_notification=False)
	{
		if ((int) $this->debug >= 1 || $this->debug == 'delete') $this->debug_message("projectmanager_bo::delete(".print_r($keys,true).",$delete_sources) this->data[pm_id] = ".$this->data['pm_id']);

		if (!is_array($keys) && (int) $keys)
		{
			$keys = array('pm_id' => (int) $keys);
		}
		$pm_id = is_null($keys) ? $this->data['pm_id'] : $keys['pm_id'];
		$project = $this->read($pm_id);

		// Project not found
		if(!$project) return 0;

		$deleted = $project;
		$deleted['pm_status'] = self::DELETED_STATUS;
		$deleted['pm_modified'] = time();
		$deleted['pm_modifier'] = $this->user;

		// if we have history switched on and not an already deleted item --> set only status deleted
		if ($this->history && $project['pm_status'] != self::DELETED_STATUS)
		{
			parent::save($deleted);

			Link::unlink(0,'projectmanager',$pm_id,'','!file','',true);	// keep the file attachments, hide the rest

			if($delete_sources)
			{
				ExecMethod2('projectmanager.projectmanager_elements_bo.delete',array('pm_id' => $pm_id),$delete_sources);
			}
			$ret = true;
		}
		else if (!$this->history || $this->history == 'history' || $this->history == 'history_admin_delete' && $this->is_admin)
		{
			if (($ret = parent::delete($keys)) && $pm_id)
			{
				// delete the projectmembers
				parent::delete_members($pm_id);

				ExecMethod2('projectmanager.projectmanager_elements_bo.delete',array('pm_id' => $pm_id),$delete_sources);

				// the following is not really necessary, as it's already one in projectmanager_elements_bo::delete
				// delete all links to project $pm_id
				Link::unlink(0,'projectmanager',$pm_id);

				$this->instanciate('constraints,milestones,pricelist,roles');

				// delete all constraints of the project
				$this->constraints->delete(array('pm_id' => $pm_id));

				// delete all milestones of the project
				$this->milestones->delete(array('pm_id' => $pm_id));

				// delete all pricelist items of the project
				$this->pricelist->delete(array('pm_id' => $pm_id));

				// delete all project specific roles
				$this->roles->delete(array('pm_id' => $pm_id));
			}
		}

		if ($project['pm_status'] != self::DELETED_STATUS)	// dont notify of final purge of already deleted items
		{
			// send email notifications and do the history logging
			if(!$skip_notification)
			{
				if (!is_object($this->tracking))
				{
					$this->tracking = new projectmanager_tracking($this);
				}
				$this->tracking->track($deleted,$project,$this->user,true);
			}
		}
		return $ret;
	}

	/**
	 * Changes or deletes entries with a specified owner (for deleteaccount hook)
	 *
	 * @param array $args hook arguments
	 * @param int $args['account_id'] account to delete
	 * @param int $args['new_owner']=0 new owner
	 * @todo test deleting an owner with replace and without
	 */
	public function  change_delete_owner(array $args)  // new_owner=0 means delete
	{
		if (!(int) $args['new_owner'])
		{
			// Direct query to skip ACL check
			$projects = $this->db->select(
				'egw_pm_projects',
				array('pm_id', 'pm_status'),
				array('pm_creator'=>$args['account_id']),
				__LINE__,__FILE__, 'projectmanager'
			);

			foreach($projects as $project)
			{
				$this->delete($project['pm_id']);
				if($this->history && $project['pm_status'] != self::DELETED_STATUS)
				{
					$this->delete($project['pm_id']);
				}
			}
		}
		else
		{
			$this->db->update(
				'egw_pm_projects',
				array('pm_creator'=>$args['new_owner']),
				array('pm_creator'=>$args['account_id']),
				__LINE__,__FILE__, 'projectmanager'
			);
		}
	}
	/**
	 * changes the data from the db-format to your work-format
	 *
	 * reimplemented to adjust the timezone of the timestamps (adding $this->tz_offset_s to get user-time)
	 * Please note, we do NOT call the method of the parent or Api\Storage\Base !!!
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 * @return array with changed data
	 */
	function db2data($data=null)
	{
		if (!is_array($data))
		{
			$data = &$this->data;
		}
		foreach($this->timestamps as $name)
		{
			if (isset($data[$name]) && $data[$name]) $data[$name] += $this->tz_offset_s;
		}

		return $data;
	}

	/**
	 * changes the data from your work-format to the db-format
	 *
	 * reimplemented to adjust the timezone of the timestamps (subtraction $this->tz_offset_s to get server-time)
	 * Please note, we do NOT call the method of the parent or Api\Storage\Base !!!
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 * @return array with changed data
	 */
	function data2db($data=null)
	{
		if (($intern = !is_array($data)))
		{
			$data = &$this->data;
		}
		foreach($this->timestamps as $name)
		{
			if (isset($data[$name]) && $data[$name]) $data[$name] -= $this->tz_offset_s;
		}
		if (substr($data['pm_completion'],-1) == '%') $data['pm_completion'] = (int) round(substr($data['pm_completion'],0,-1));

		return $data;
	}

	const DEFAULT_ID_GERERATION_FORMAT = 'P-%Y-%04ix';
	const DEFAULT_ID_GERERATION_FORMAT_SUB = '%px/%04ix';

	/**
	 * generate a project-ID / generated by Api\Config format
	 *
	 * @param boolean $set_data =true set generated number in $this->data, default true
	 * @param string $parent ='' pm_number of parent
	 * @return string the new pm_number
	 */
	function generate_pm_number($set_data=true,$parent='')
	{
		// migrate evtl. set config to new forced preference (once)
		static $prefs = null;
		foreach(array(
			'ID_GENERATION_FORMAT' => 'id-generation-format',
			'ID_GENERATION_FORMAT_SUB' => 'id-generation-format-sub'
		) as $config => $pref)
		{
			if (!empty($this->config[$config]))
			{
				if (!isset($prefs)) $prefs = new Api\Preferences('default');
				$prefs->add('projectmanager', $pref, $this->config[$config], 'default');
				Api\Config::save_value($config, null, 'projectmanager');
				$this->prefs[$pref] = $this->config[$config];
				unset($this->config[$config]);
			}
		}
		if (isset($prefs))
		{
			$prefs->save_repository(false, 'default');
		}

		if ($parent === '')
		{
			$format = empty($this->prefs['id-generation-format']) ?
				self::DEFAULT_ID_GERERATION_FORMAT : $this->prefs['id-generation-format'];
		}
		else
		{
			$format = empty($this->prefs['id-generation-format-sub']) ?
				self::DEFAULT_ID_GERERATION_FORMAT_SUB : $this->prefs['id-generation-format-sub'];
		}

		$pm_format = '';
		$index = false;
		for($i = 0;$i < strlen($format);$i++)
		{
			//echo "i:$i char=".$format[$i].'<br>';
			if($format[$i] == '%')
			{
				$filler = $format[++$i];
				$count = $format[++$i];
				if(is_numeric($count) && is_numeric($filler))
				{
					// all right ...
				}
				elseif(is_numeric($count) && is_string($filler))
				{
					// if filler is nonnummerical, that should work too as padding char
					// note thar char padding requires a preceding '
					$filler="'".$filler;
				}
				elseif(is_numeric($filler))
				{
					$count = $filler;	// only one part given (e.g. %4n), fill with '0'
					$filler = '0';
					$i--;
				}
				else
				{
					$filler = $count = '';	// no specialism
					$i -= 2;
				}

				$name = substr($format, $i + 1, 2);
				if($name == 'px' && $parent !== '')	// parent id
				{
					$pm_format .= $parent;
					$i += 2;
				}
				elseif($name == 'ix')	// index
				{
					if(!$index)	// insert only one index
					{
						$pm_format .= ($filler && $count ? "%{$filler}{$count}s" :
							($count ? "%0{$count}s" : "%s"));
						$index = true;
					}
					$i += 2;
				}
				else	// date
				{
					$date = '';
					//while(in_array($char = $format[++$i], array('d','D','j','l','N','S','w','z','W','F','m','M','n','t','L','o',
					//	'Y','y','a','A','B','g','G','h','H','i','s','u','e','I','O','P','T','Z','c','r','U')))
					//{
					//	$date .= $char;
					//}
					//echo " Char at Pos: ".++$i.":".$format[$x]."<br>";
					// loop through thevrest until we find the next % to indicate the next replacement
					for($x = ++$i;$x < strlen($format);$x++)
					{
						//echo "x: $x ($i) char here:".$format[$x]."<br>";
						if ($format[$x] == "%")
						{
							break;
						}
						$date .= $format[$x];
						$i++;
					}
					//echo "Date format:".$date."Filler:$filler, Count:$count<br>";
					$pm_format .= sprintf($filler && $count ? "%{$filler}{$count}s" :
							($count ? "%0{$count}s" : "%s"), date($date));
					//echo "PM-Date format:".$pm_format."<br>";
					$i--;
				}
			}
			else	// normal character
			{
				$pm_format .= $format[$i];
			}
		}
		if(!$index && $this->not_unique(array('pm_number' => $pm_format)))	// no index given and not unique
		{
			// have to use default
			$pm_format = $parent === '' ? sprintf('P-%04s-%%04d', date('Y')) : $parent.'/%04d';
		}
		elseif(!$index)
		{
			$pm_number = $pm_format;
		}
		if(!isset($pm_number))
		{
			$n = 1;
			do
			{
				$pm_number = sprintf($pm_format, $n++);
			}
			while ($this->not_unique(array('pm_number' => $pm_number)));
		}

		if ($set_data) $this->data['pm_number'] = $pm_number;

		return $pm_number;
	}

	/**
	 * checks if the given user has enough rights for a certain operation
	 *
	 * Rights are given via owner grants or role based Acl
	 *
	 * @param int $required Acl::READ, Acl::EDIT, Acl::ADD, EGW_ACL_ADD_TIMESHEET, Acl::DELETE, EGW_ACL_BUDGET, EGW_ACL_EDIT_BUDGET
	 * @param array|int $data =null project or project-id to use, default the project in $this->data
	 * @param boolean $no_cache =false should a cached value be used, if available, or not
	 * @param int $user =null for which user to check, default current user
	 * @return boolean true if the rights are ok, false if not or null if entry not found
	 */
	function check_acl($required,$data=0,$no_cache=false,$user=null)
	{
		static $cache = array();

		$pm_id = (!$data ? $this->data['pm_id'] : (is_array($data) ? $data['pm_id'] : $data));

		if (!$user) $user = $this->user;
		if ($user == $this->user)
		{
			$grants = $this->grants;
			$cached =& $cache[$pm_id];
			$rights =& $cached['rights'];
			$private =& $cached['private'];
			$grants_from_groups =& $cached['group'];
		}
		else	// user other then current one, do NO caching at all
		{
			$grants = $GLOBALS['egw']->acl->get_grants('projectmanager',true,$user);
		}

		if (!$pm_id)	// new entry, everything allowed, but delete
		{
			return $required != Acl::DELETE;
		}
		if (!isset($rights) || $no_cache)	// check if we have a cache entry for $pm_id
		{
			if ($data)
			{
				if (!is_array($data))
				{
					$data_backup =& $this->data; unset($this->data);
					$data = parent::read($data);
					$this->data =& $data_backup; unset($data_backup);

					if (!$data) return null;	// $pm_id not found ==> no rights
				}
			}
			else
			{
				$data =& $this->data;
			}
			$private = $data['pm_access'] === 'private';
			// rights come from owner grants or role based Acl
			$memberships = $GLOBALS['egw']->accounts->memberships($user);
			$member_from_groups = array_intersect_key((array)$data['pm_members'], $memberships);
			$grants_from_groups = 0;
			foreach (array_keys($member_from_groups) as $member_from_group) {
				$grants_from_groups = $grants_from_groups | (int) $data['pm_members'][$member_from_group]['role_acl'];
			}

			$rights = (int) $grants[$data['pm_creator']] | (int) $data['pm_members'][$user]['role_acl'] | $grants_from_groups;

			// for status or times accounting-type (no accounting) remove the budget-rights from everyone
			if ($data['pm_accounting_type'] == 'status' || $data['pm_accounting_type'] == 'times')
			{
				$rights &= ~(EGW_ACL_BUDGET | EGW_ACL_EDIT_BUDGET);
			}
			// anonymous access implies read rights for everyone
			if(is_array($data) && $data['pm_access'] === 'anonym')
			{
				$rights |= Acl::READ;
			}
		}
		// private project need either a private grant or a role ACL
		if($private && !($rights & Acl::PRIVAT) && is_array($data) && (
				// No role
				empty($data['pm_members'][$user]) ||
				// Role, but not enough access
				!empty($data['pm_members'][$user]) && !((int)$data['pm_members'][$user]['role_acl'] & $required)
			) && empty($member_from_group))
		{
			$access = false;
		}
		// Role via group, but not enough access
		else if($private && !($rights & Acl::PRIVAT) && !empty($member_from_groups) && !($grants_from_groups & $required))
		{
			return false;
		}
		elseif ($required & Acl::READ)       // read-rights are implied by all other rights, but EGW_ACL_ADD_TIMESHEET
		{
			$access = (boolean) ($rights & ~EGW_ACL_ADD_TIMESHEET);
		}
		else
		{
			if($required == EGW_ACL_BUDGET)
			{
				$required |= EGW_ACL_EDIT_BUDGET;
			}    // EDIT_BUDGET implies BUDGET

			$access = (boolean) ($rights & $required);
		}

		if(($required & Acl::DELETE) && $this->config_data['history'] == 'history_admin_delete' &&
			$data['pm_status'] == self::DELETED_STATUS)
		{
			$access = !empty($GLOBALS['egw_info']['user']['apps']['admin']);
		}
		if ((int) $this->debug >= 2 || $this->debug == 'check_acl') $this->debug_message(__METHOD__."($required,pm_id=$pm_id,$no_cache,$user) rights=$rights returning ".array2string($access));
		//error_log(__METHOD__."($required) pm_id=$pm_id, data[pm_access]=".(is_array($data) ? array2string($data['pm_access']) : 'data='.array2string($data))." returning ".array2string($access));
		return $access;
	}

	/**
	 * Read a project
	 *
	 * reimplemented to add an Acl check
	 *
	 * @param array $keys
	 * @return array|boolean array with project, null if project not found or false if no perms to view it
	 */
	function read($keys, $extra_cols = '', $join = '')
	{
		if (!parent::read($keys, $extra_cols, $join))
		{
			return null;
		}
		if (!$this->check_acl(Acl::READ))
		{
			return false;
		}
		return $this->data;
	}

	/**
	 * get title for an project identified by $entry
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param int|array $entry int pm_id or array with project entry
	 * @return string/boolean string with title, null if project not found or false if no perms to view it
	 */
	public static function link_title( $entry )
	{
		if (!is_array($entry))
		{
			$bo = new projectmanager_bo();

			// reading entry incl. read ACL check, possibly returning false
			$entry = $bo->read($pm_id=$entry);

			// even though ADD_TIMESHEET means no read, we let them see the title
			if(!$entry && $bo->check_acl(EGW_ACL_ADD_TIMESHEET, $pm_id))
			{
				// this is achieved by calling parent::read() which does NOT implement ACL
				$so = new projectmanager_so();
				$entry = $so->read($pm_id);
			}
		}
		if (!$entry)
		{
			return $entry;
		}
		return $entry['pm_number'].': '.$entry['pm_title'];
	}

	/**
	 * get titles for multiple project identified by $ids
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param array $ids int pm_id or array with project entry
	 * @return array or titles, see link_title
	 */
	function link_titles( array $ids )
	{
		$titles = array();
		if (($projects = $this->search(array('pm_id' => $ids),'pm_number,pm_title')))
		{
			foreach($projects as $project)
			{
				$titles[$project['pm_id']] = $this->link_title($project);
			}
		}
		// we assume all not returned projects are not readable by the user, as we notify Link about all deletes
		foreach($ids as $id)
		{
			if (!isset($titles[$id]))
			{
				$titles[$id] = false;
			}
		}
		return $titles;
	}

	/**
	 * query projectmanager for entries matching $pattern
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param string $pattern pattern to search
	 * @param array $options Array of options for the search
	 * @return array with pm_id - title pairs of the matching entries
	 */
	function link_query( $pattern, Array &$options = array() )
	{
		$limit = false;
		$need_count = false;
		if($options['start'] || $options['num_rows'])
		{
			$limit = array((int)$options['start'], (int)$options['num_rows']);
			$need_count = true;
		}
		$result = array();
		$sort_order = $this->prefs['link_sort_order'];
		// Protect against bad preference value
		$order = isset($this->field2label[explode(' ', $sort_order)[0]]) ? $sort_order : 'pm_created DESC';
		foreach((array) $this->search($pattern,false,$order,'','%',false,'OR',$limit,array('pm_status'=>'active'), true, $need_count) as $prj )
		{
			if ($prj['pm_id']) $result[$prj['pm_id']] = $this->link_title($prj);
		}
		$options['total'] = $need_count ? $this->total : count($result);
		return $result;
	}

	/**
	 * Check access to the projects file store
	 *
	 * We currently map file access rights:
	 *  - file read rights = project read rights
	 *  - file write or delete rights = project edit rights
	 *
	 * @ToDo Implement own Acl rights for file access
	 * @param int $id pm_id of project
	 * @param int $check Acl::READ for read and Acl::EDIT for write or delete access
	 * @param string $rel_path path relative to project directory (currently not used)
	 * @param int $user =null for which user to check, default current user
	 * @return boolean true if access is granted or false otherwise
	 */
	function file_access($id,$check,$rel_path,$user=null)
	{
		unset($rel_path);	// not used, but required by funciton signature

		return $this->check_acl($check,$id,false,$user);
	}

	/**
	 * gets all ancestors of a given project (calls itself recursively)
	 *
	 * A project P is the parent of another project C, if link_id1=P.pm_id and link_id2=C.pm_id !
	 * To get all parents of a project C, we use all links to the project, which link_id2=C.pm_id.
	 *
	 * @param int $pm_id =0 id or 0 to use $this->pm_id
	 * @param array $ancestors =array() already identified ancestors, default none
	 * @return array with ancestors
	 */
	public static function ancestors($pm_id=0,$ancestors=array())
	{
		static $ancestors_cache = array();	// some caching

		if (!$pm_id) return false;

		if (!isset($ancestors_cache[$pm_id]))
		{
			$ancestors_cache[$pm_id] = array();

			// read all projectmanager entries attached to this one
			foreach(array_keys(Link::get_links('projectmanager',$pm_id,'projectmanager')) as $link_id)
			{
				// we need to read the complete link, to know if the entry is a child (link_id1 == pm_id)
				$link = Link::get_link($link_id);
				if ($link['link_id1'] == $pm_id)
				{
					continue;	// we are the parent in this link ==> ignore it
				}
				$parent = (int) $link['link_id1'];
				if (!in_array($parent,$ancestors_cache[$pm_id]))
				{
					$ancestors_cache[$pm_id][] = $parent;
					// now we call ourselves recursively to get all parents of the parents
					$ancestors_cache[$pm_id] = static::ancestors($parent,$ancestors_cache[$pm_id]);
				}
			}
		}
		//echo "<p>ancestors($pm_id)=".print_r($ancestors_cache[$pm_id],true)."</p>\n";
		return array_merge($ancestors,$ancestors_cache[$pm_id]);
	}

	/**
	 * gets recursive all children (only projects) of a given project (calls itself recursively)
	 *
	 * A project P is the parent of another project C, if link_id1=P.pm_id and link_id2=C.pm_id !
	 * To get all children of a project C, we use all links to the project, which link_id1=C.pm_id.
	 *
	 * @param int $pm_id =0 id or 0 to use $this->pm_id
	 * @param array $children =array() already identified ancestors, default none
	 * @return array with children
	 */
	function children($pm_id=0,$children=array())
	{
		static $children_cache = array();	// some caching

		if (!$pm_id && !($pm_id = $this->pm_id)) return false;

		if (!isset($children_cache[$pm_id]))
		{
			$children_cache[$pm_id] = array();

			// read all projectmanager entries attached to this one
			foreach(array_keys(Link::get_links('projectmanager',$pm_id,'projectmanager')) as $link_id)
			{
				// we need to read the complete link, to know if the entry is a child (link_id1 == pm_id)
				$link = Link::get_link($link_id);
				if ($link['link_id1'] != $pm_id)
				{
					continue;	// we are NOT the parent in this link ==> ignore it
				}
				$child = (int) $link['link_id2'];
				if (!in_array($child,$children_cache[$pm_id]))
				{
					$children_cache[$pm_id][] = $child;
					// now we call ourselves recursively to get all parents of the parents
					$children_cache[$pm_id] = $this->children($child,$children_cache[$pm_id]);
				}
			}
		}
		//echo "<p>children($pm_id)=".print_r($children_cache[$pm_id],true)."</p>\n";
		return array_merge($children,$children_cache[$pm_id]);
	}

	/**
	 * Query the project-tree from the DB, project tree is indexed by a path consisting of pm_id's delimited by slashes (/)
	 *
	 * @param array $filter =array('pm_status' => 'active') filter for the search, default active projects
	 * @param string $filter_op ='AND' AND or OR filters together, default AND
	 * @param array|string $_parents =null pm_id(s) of parents or null to return whole tree
	 *  if $_parents is given we also return number of (grand-)children as value for key "children"
	 * @param int $_pm_id =null pm_id of (current) project, which need to be included for $_parents==='mains'
	 * @return array with path => array(pm_id,pm_number,pm_title,pm_parent) pairs
	 */
	function get_project_tree($filter = array('pm_status' => 'active'),$filter_op='AND', $_parents=null, $_pm_id=null)
	{
		$projects = $extra_cols = array();
		$parents = !isset($_parents) ? 'mains' : $_parents;
		//error_log(__METHOD__."(".array2string($filter).", '$filter_op, ".array2string($_parents).") parents=".array2string($parents));

		// if parents given, also return number of (grand-)children
		if (isset($_parents)) $extra_cols[] = 'children';

		$sort_order = $this->prefs['link_sort_order'];
		// Protect against bad preference value
		$order = isset($this->field2label[explode(' ', $sort_order)[0]]) ? $sort_order : 'pm_status,pm_number';

		// get the children
		while (($children = $this->search($filter,$this->table_name.'.pm_id AS pm_id,pm_number,pm_title,'.$this->links_table.'.link_id1 AS pm_parent,pm_status',
			$order,$extra_cols,'',false,$filter_op,false,array('subs_or_mains' => $parents))))
		{
			//error_log(__METHOD__."(".array2string($filter).", '$filter_op, ".array2string($_parents).") parents=".array2string($parents)." --> children=".array2string($children));
			// sort the children behind the parents
			$parents = $both = array();
			foreach ($projects as $parent)
			{
				//echo "Parent:".$parent['path']."<br>";
				$arr = explode("/",$parent['path']);
				$search = array_pop($arr);
				if (count($arr) >= 1 && in_array($search,$arr))
				{
					error_log(lang('ERROR: Rekursion found: Id %1 more than once in Projectpath, while building Projecttree:',$search).' '.$parent['path']."\n".array2string($projects[$parent['path']]));
					break 2;
				}
				$both[$parent['path']] = $parent;

				foreach($children as $key => $child)
				{
					if ($child['pm_parent'] == $parent['pm_id'])
					{
						$child['path'] = $parent['path'] . '/' . $child['pm_id'];
						$both[$child['path']] = $child;
						$parents[] = $child['pm_id'];
						unset($children[$key]);
					}
				}
			}
			// mains or orphans
			foreach ($children as $child)
			{
				$child['path'] = '/' . $child['pm_id'];
				$both[$child['path']] = $child;
				// only query children, if neccessary
				if (!isset($child['children']) || $child['children']) $parents[] = $child['pm_id'];
			}
			$projects = $both;

			// only return one level if $_parents is set, unless $_parents === 'mains' and current-project is not yet included
			if (!$parents || isset($_parents) && ($_parents !== 'mains' || !$_pm_id || in_array($_pm_id, $parents)))
			{
				break;
			}
		}
		//error_log(__METHOD__."(".array2string($filter).", '$filter_op, ".array2string($_parents).") current_project=$current_project --> returning ".array2string($projects));
		return $projects;
	}

	/**
	 * write a debug-message to the log-file $this->logfile (if set)
	 *
	 * @param string $msg
	 */
	static function log2file($msg)
	{
		if (static::$logfile && ($f = @fopen(static::$logfile,'a+')))
		{
			fwrite($f,date('Y-m-d H:i:s: ').Api\Accounts::username($GLOBALS['egw_info']['user']['account_id'])."\n");
			fwrite($f,$msg."\n\n");
			fclose($f);
		}
	}

	/**
	 * EITHER echos a (preformatted / no-html) debug-message OR logs it to a file
	 *
	 * @param string $msg
	 */
	public static function debug_message($msg)
	{
		//$msg = 'Backtrace: '.function_backtrace(2)."\n".$msg;

		if (!static::$logfile)
		{
			echo '<pre>'.$msg."</pre>\n";
		}
		elseif(static::$logfile == 'error_log')
		{
			error_log($msg);
		}
		else
		{
			static::log2file($msg);
		}
	}

	/**
	 * Add a timespan to a given datetime, taking into account the availibility and worktimes of the user
	 *
	 * ToDo: take exclusivly blocked times (calendar) into account
	 *
	 * @param int $start start timestamp (usertime)
	 * @param int $time working time in minutes to add, 0 advances to the next working time
	 * @param int $uid user-id
	 * @return int|boolean end-time or false if it cant be calculated because user has no availibility or worktime
	 */
	function date_add($start,$time,$uid)
	{
		// we cache the user-prefs with the working times globally, as they are expensive to read
		$user_prefs =& $GLOBALS['egw_info']['projectmanager']['user_prefs'][$uid];
		if (!is_array($user_prefs))
		{
			if ($uid == $GLOBALS['egw_info']['user']['account_id'])
			{
				$user_prefs = $GLOBALS['egw_info']['user']['preferences']['projectmanager'];
			}
			else
			{
				$prefs = new Api\Preferences($uid);
				$prefs->read_repository();
				$user_prefs =& $prefs->data['projectmanager'];
				unset($prefs);
			}
			// calculate total weekly worktime
			for($day=$user_prefs['duration']=0; $day <= 6; ++$day)
			{
				$user_prefs['duration'] += $user_prefs['duration_'.$day];
			}
		}
		$availibility = 1.0;
		if (isset($this->data['pm_members'][$uid]))
		{
			$availibility = $this->data['pm_members'][$uid]['member_availibility'] / 100.0;
		}
		$general = $this->get_availibility($uid);
		if (isset($general[$uid]))
		{
			$availibility *= $general[$uid] / 100.0;
		}
		// if user has no availibility or no working duration ==> fail
		if (!$availibility || !$user_prefs['duration'])
		{
			return false;
		}
		$time_s = $time * 60 / $availibility;

		if (!is_object($this->bocal))
		{
			$this->bocal = new calendar_bo();
		}
		$events =& $this->bocal->search(array(
			'start' => $start,
			'end'   => $start+max(10*$time,30*24*60*60),
			'users' => $uid,
			'show_rejected' => false,
			'ignore_acl' => true,
		));
		if ($events) $event = array_shift($events);

		$end_s = $start;
		// we use do-while to allow with time=0 to advance to the next working time
		do {
			// ignore non-blocking events or events already over
			while ($event && ($event['non_blocking'] || $event['end'] <= $end_s))
			{
				//echo "<p>ignoring event $event[title]: ".date('Y-m-d H:i',$event['start'])."</p>\n";
				$event = array_shift($events);
			}
			$day = date('w',$end_s);	// 0=Sun, 1=Mon, ...
			$work_start_s = $user_prefs['start_'.$day] * 60;
			$max_add_s = 60 * $user_prefs['duration_'.$day];
			$time_of_day_s = $end_s - mktime(0,0,0,date('m',$end_s),date('d',$end_s),date('Y',$end_s));

			// befor workday starts ==> go to start of workday
			if ($max_add_s && $time_of_day_s < $work_start_s)
			{
				$end_s += $work_start_s - $time_of_day_s;
			}
			// after workday ends or non-working day ==> go to start of NEXT workday
			elseif (!$max_add_s || $time_of_day_s >= $work_start_s+$max_add_s)	// after workday ends
			{
				//echo date('D Y-m-d H:i',$end_s)." ==> go to next day: work_start_s=$work_start_s, time_of_day_s=$time_of_day_s, max_add_s=$max_add_s<br>\n";
				do {
					$day = ($day+1) % 7;
					$end_s = mktime($user_prefs['start_'.$day]/60,$user_prefs['start_'.$day]%60,0,date('m',$end_s),date('d',$end_s)+1,date('Y',$end_s));
				} while (!($max_add_s = 60 * $user_prefs['duration_'.$day]));
			}
			// in the working period ==> adjust max_add_s accordingly
			else
			{
				$max_add_s -= $time_of_day_s - $work_start_s;
			}
			$add_s = min($max_add_s,$time_s);

			//echo date('D Y-m-d H:i',$end_s)." + ".($add_s/60/60)."h / ".($time_s/60/60)."h<br>\n";

			if ($event)
			{
				//echo "<p>checking event $event[title] (".date('Y-m-d H:i',$event['start']).") against end_s=$end_s=".date('Y-m-d H:i',$end_s)." + add_s=$add_s</p>\n";
				if ($end_s+$add_s > $event['start'])	// event overlaps added period
				{
					$time_s -= max(0,$event['start'] - $end_s);	// add only time til events starts (if any)
					$end_s = $event['end'];				// set time for further calculation to event end
					//echo "<p>==> event overlaps: time_s=$time_s, end_s=$end_s now</p>\n";
					$event = array_shift($events);		// advance to next event
					continue;
				}
			}
			$end_s += $add_s;
			$time_s -= $add_s;
		} while ($time_s > 0);

		if ((int) $this->debug >= 3 || $this->debug == 'date_add') $this->debug_message("projectmanager_bo::date_add($start=".date('D Y-m-d H:i',$start).", $time=".($time/60.0)."h, $uid)=".date('D Y-m-d H:i',$end_s));

		return $end_s;
	}

	/**
	 * Copies a project
	 *
	 * @param int $source id of project to copy
	 * @param int $only_stage =0 0=both stages plus saving the project, 1=copy of the project, 2=copying the element tree
	 * @param string $parent_number ='' number of the parent project, to create a sub-project-number
	 * @return int|boolean successful copy new pm_id or true if $only_stage==1, false otherwise (eg. permission denied)
	 */
	function copy($source,$only_stage=0,$parent_number='')
	{
		if ((int) $this->debug >= 1 || $this->debug == 'copy') $this->debug_message("projectmanager_bo::copy($source,$only_stage)");

		if ($only_stage == 2)
		{
			if (!(int)$this->data['pm_id']) return false;

			$data_backup = $this->data;
		}
		if (!$this->read((int) $source) || !$this->check_acl(Acl::READ))
		{
			if ((int) $this->debug >= 1 || $this->debug == 'copy') $this->debug_message("projectmanager_bo::copy($source,$only_stage) returning false (not found or no perms), data=".print_r($this->data,true));
			return false;
		}
		if ($only_stage == 2)
		{
			$this->data = $data_backup;
			unset($data_backup);
		}
		else
		{
			// if user has no budget rights on the source, we need to unset the budget fields
			if ($this->check_acl(EGW_ACL_BUDGET))
			{
				include_once(EGW_INCLUDE_ROOT.'/projectmanager/inc/class.datasource.inc.php');
				foreach(array(PM_PLANNED_BUDGET => 'pm_planned_budget',PM_USED_BUDGET => 'pm_used_budget') as $id => $key)
				{
					unset($this->data[$key]);
					$this->data['pm_overwrite'] &= ~$id;
				}
			}
			// we unset a view things, as this should be a new project
			foreach(array('pm_id','pm_number','pm_creator','pm_created','pm_modified','pm_modifier') as $key)
			{
				unset($this->data[$key]);
			}
			$this->data['pm_status'] = 'active';

			if ($parent_number) $this->generate_pm_number(true,$parent_number);

			if ($only_stage == 1)
			{
				return true;
			}
			if ($this->save() != 0) return false;
		}
		$this->instanciate('milestones,constraints');

		// copying the milestones
		$milestones = $this->milestones->copy((int)$source,$this->data['pm_id']);

		// copying the element tree
		include_once(EGW_INCLUDE_ROOT.'/projectmanager/inc/class.projectmanager_elements_bo.inc.php');
		$boelements = new projectmanager_elements_bo($this->data['pm_id']);

		if (($elements = $boelements->copytree((int) $source)))
		{
			// copying the constrains
			$this->constraints->copy((int)$source,$elements,$milestones,$boelements->pm_id);
		}

		// Copy files
		$dir = '/apps/projectmanager/'.$source;
		$files = Api\Vfs::scandir($dir);
		foreach($files as $key => &$file)
		{
			if($file == '.' || $file == '..' || Api\Vfs::is_link($dir.'/'.$file))
			{
				unset($files[$key]);
				continue;
			}
			$file = $dir . '/' . $file;
		}
		Api\Vfs::copy_files($files, "/apps/projectmanager/{$this->data['pm_id']}");

		return $boelements->pm_id;
	}


	/**
	 * Send all async projectmanager notification
	 *
	 * Called via the async service job 'projectmanager-async-notification'
	 */
	function async_notification()
	{
		if (!($users = $this->users_with_open_entries()))
		{
			return;
		}
		//error_log(__METHOD__."() users with open entries: ".implode(', ',$users));

		$save_account_id = $GLOBALS['egw_info']['user']['account_id'];
		$save_prefs      = $GLOBALS['egw_info']['user']['preferences'];
		foreach($users as $user)
		{
			if (!($email = $GLOBALS['egw']->accounts->id2name($user,'account_email'))) continue;
			// create the environment for $user
			$this->user = $GLOBALS['egw_info']['user']['account_id'] = $user;
			$GLOBALS['egw']->preferences->__construct($user);
			$GLOBALS['egw_info']['user']['preferences'] = $GLOBALS['egw']->preferences->read_repository(false);
			$GLOBALS['egw']->acl->__construct($user);
			$this->so = new projectmanager_so();

			// Check notification preferences
			$assigned = $GLOBALS['egw_info']['user']['preferences']['projectmanager']['notify_assigned'];
			if($assigned == '0')
			{
				// User does not want notifications
				//error_log(__METHOD__."() checking notify_assigned preference == 0, user $user ($email) does not want notifications");
				continue;
			}
			if(!is_array($assigned))
			{
				$assigned = explode(',',$assigned);
			}

			// Only get projects this user is involved in and wants notification for (or creator)
			$filter = ['('.$this->db->column_data_implode(' OR ',Array(
				$this->table_name.'.pm_creator = ' . $user,
				'('.$this->db->column_data_implode(' AND ', Array(
						$this->members_table.'.member_uid = ' . $user,
						$this->db->column_data_implode(',', [$this->members_table.'.role_id' => $assigned]),
				)).')'
			)).')'];

			$notified_pm_ids = array();
			$notify_events = array(
				'notify_due_planned'   => 'pm_planned_end',
				'notify_due_real'      => 'pm_real_end',
				'notify_start_planned' => 'pm_planned_start',
				'notify_start_real'    => 'pm_real_start',
			);
			if(!empty($this->config['custom_notification']['custom_date']['field']))
			{
				$notify_events['notify_custom_date'] = self::CF_PREFIX . $this->config['custom_notification']['custom_date']['field'];
			}

			foreach($notify_events as $pref => $filter_field)
			{
				// Custom is in config, it has no preference
				if($pref != 'notify_custom_date')
				{
					if(!($pref_value = $GLOBALS['egw_info']['user']['preferences']['projectmanager'][$pref]))
					{
						continue;
					}
					if($pref_value === '0')
					{
						continue;
					}
				}

				$today = time()+24*60*60*(int)$pref_value;
				$tomorrow = $today + 24*60*60;

				// Filter date
				$filter[1] = "$today <= $filter_field AND $filter_field < $tomorrow";
				if($pref == 'notify_custom_date')
				{
					unset($filter[1]);
					$filter[$filter_field] = Api\DateTime::to($today, 'Y-m-d');
				}

				//error_log(__METHOD__."() checking with $pref filter '".print_r($filter,true)."' ($pref_value) for user $user ($email)");

				$results = $this->search('',TRUE, '', '', '', FALSE, 'AND', FALSE, $filter	);
				//error_log(__METHOD__.  "  which gives these projects: " . print_r($results ? array_column($results,'pm_id') : '',true));
				if(!$results || !is_array($results))
				{
					continue;
				}
				foreach($results as $_project)
				{
					// check if we already send a notification for that project, eg. starting and due on same day
					if (in_array($_project['pm_id'],$notified_pm_ids)) continue;

					$project = $this->read($_project['pm_id']);

					if(!$project)
					{
						$notified_pm_ids[] = $_project['pm_id'];
						continue;
					}

					if (is_null($this->tracking) || $this->tracking->user != $user)
					{
						$this->tracking = new projectmanager_tracking($this);
					}
					$prefix = lang(Api\Link::get_registry('projectmanager','entry'));
					switch($pref)
					{
						case 'notify_due_planned':
							$project['prefix'] = lang('Due %1',$prefix) . lang('- planned');
							$project['message'] = lang('%1 you are responsible for is due at %2',$prefix,
								$this->tracking->datetime($project['pm_planned_end'],false));
							break;
						case 'notify_due_real':
							$project['prefix'] = lang('Due %1',$prefix);
							$project['message'] = lang('%1 you are responsible for is due at %2',$prefix,
								$this->tracking->datetime($project['pm_real_end'],false));
							break;
						case 'notify_start_planned':
							$project['prefix'] = lang('Starting %1',$prefix) . lang('- planned');
							$project['message'] = lang('%1 you are responsible for is starting at %2',$prefix,
								$this->tracking->datetime($project['pm_planned_start'],null));
							break;
						case 'notify_start_real':
							$project['prefix'] = lang('Starting %1',$prefix);
							$project['message'] = lang('%1 you are responsible for is starting at %2',$prefix,
								$this->tracking->datetime($project['pm_real_start'],null));
							break;
						case 'notify_custom_date':
							$project['custom_notification'] = 'custom_date';
							// Don't check a preference, it's not there
							$pref = null;
					}
					//("notifiying $user($email) about {$project['pm_title']}: {$project['message']}");

					// Allow notification to have HTML
					$this->tracking->html_content_allow = true;
					$this->tracking->send_notification($project,null,$email,$user,$pref);

					$notified_pm_ids[] = $project['pm_id'];
				}
			}
		}

		$GLOBALS['egw_info']['user']['account_id']  = $save_account_id;
		$GLOBALS['egw_info']['user']['preferences'] = $save_prefs;
	}

}

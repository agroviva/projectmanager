<?php
/**************************************************************************\
* eGroupWare - ProjectManager: Admin-, Preferences- and SideboxMenu-Hooks  *
* http://www.eGroupWare.org                                                *
* Written and (c) 2005 by Ralf Becker <RalfBecker@outdoor-training.de>     *
* -------------------------------------------------------                  *
*  This program is free software; you can redistribute it and/or modify it *
*  under the terms of the GNU General Public License as published by the   *
*  Free Software Foundation; either version 2 of the License, or (at your  *
*  option) any later version.                                              *
\**************************************************************************/

/* $Id$ */

class pm_admin_prefs_sidebox_hooks
{
	var $public_functions = array(
//		'check_set_default_prefs' => true,
	);
	var $weekdays = array(
		1 => 'monday',
		2 => 'tuesday',
		3 => 'wednesday',
		4 => 'thursday',
		5 => 'friday',
		6 => 'saturday',
		0 => 'sunday',
	);
	var $config = array();

	function pm_admin_prefs_sidebox_hooks()
	{
		$config =& CreateObject('phpgwapi.config','projectmanager');
		$config->read_repository();
		$this->config =& $config->config_data;
		unset($config);
	}

	/**
	 * hooks to build projectmanager's sidebox-menu plus the admin and preferences sections
	 *
	 * @param string/array $args hook args
	 */
	function all_hooks($args)
	{
		$appname = 'projectmanager';
		$location = is_array($args) ? $args['location'] : $args;
		//echo "<p>ranking_admin_prefs_sidebox_hooks::all_hooks(".print_r($args,True).") appname='$appname', location='$location'</p>\n";

		if ($location == 'sidebox_menu')
		{
			// project-dropdown in sidebox menu
			if (!is_object($GLOBALS['egw']->html))
			{
				$GLOBALS['egw']->html =& CreateObject('phpgwapi.html');
			}
			if (!is_object($GLOBALS['boprojectmanager']))
			{
				// dont assign it to $GLOBALS['boprojectmanager'], as the constructor does it!!!
				CreateObject('projectmanager.uiprojectmanager');
			}
			if (($pm_id = (int) $_REQUEST['pm_id']))
			{
				$GLOBALS['egw']->session->appsession('pm_id','projectmanager',$pm_id);
			}
			else
			{
				$pm_id = (int) $GLOBALS['egw']->session->appsession('pm_id','projectmanager');
			}
			$projects = array();
			foreach((array)$GLOBALS['boprojectmanager']->search(array(
				'pm_status' => 'active',
				'pm_id'     => $pm_id,
			),$GLOBALS['boprojectmanager']->table_name.'.pm_id AS pm_id,pm_number,pm_title','pm_modified','','',False,'OR') as $project)
			{
				$projects[$project['pm_id']] = array(
					'label' => $project['pm_number'],
					'title' => $project['pm_title'],
				);
			}
			if (!$pm_id) 
			{
				$projects[0] = lang('select a project');
			}
			switch($_GET['menuaction'])
			{
				case 'projectmanager.ganttchart.show':
					$selbox_action = 'projectmanager.ganttchart.show';
					break;
				default:
					$selbox_action = 'projectmanager.uiprojectelements.index';
					break;
			}
			$file = array(
				array(
					'text' => $GLOBALS['egw']->html->select('pm_id',$pm_id,$projects,true,
						' onchange="location.href=\''.$GLOBALS['egw']->link('/index.php',array(
							'menuaction' => $selbox_action,
						)).'&pm_id=\'+this.value;" title="'.$GLOBALS['egw']->html->htmlspecialchars($projects[$pm_id]).'"'),
					'no_lang' => True,
					'link' => False
				),
				'Projectlist' => $GLOBALS['egw']->link('/index.php',array(
					'menuaction' => 'projectmanager.uiprojectmanager.index' )),
				array(
					'text' => 'Elementlist',
					'link' => $pm_id ? $GLOBALS['egw']->link('/index.php',array(
						'menuaction' => 'projectmanager.uiprojectelements.index', 
					)) : False,
				),
				array(
					'text' => 'Ganttchart',
					'link' => $pm_id ? $GLOBALS['egw']->link('/index.php',array(
						'menuaction' => 'projectmanager.ganttchart.show',
					)) : False,
				),
			);
			display_sidebox($appname,$GLOBALS['egw_info']['apps'][$appname]['title'].' '.lang('Menu'),$file);
		}

		if ($GLOBALS['egw_info']['user']['apps']['preferences'] && $location != 'admin')
		{
			$file = array(
				'Preferences'     => $GLOBALS['egw']->link('/index.php','menuaction=preferences.uisettings.index&appname='.$appname),
				'Grant Access'    => $GLOBALS['egw']->link('/index.php','menuaction=preferences.uiaclprefs.index&acl_app='.$appname),
				'Edit Categories' => $GLOBALS['egw']->link('/index.php','menuaction=preferences.uicategories.index&cats_app=' . $appname . '&cats_level=True&global_cats=True')
			);
			if (!$this->config['allow_change_workingtimes'] && !$GLOBALS['egw_info']['user']['apps']['admin'])
			{
				unset($file['Preferences']);	// atm. prefs are only working times
			}
			if ($location == 'preferences')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Preferences'),$file);
			}
		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'] && $location != 'preferences')
		{
			$file = Array(
				'Site configuration' => $GLOBALS['egw']->link('/index.php','menuaction=projectmanager.admin.config'),
				'Global Categories'  => $GLOBALS['egw']->link('/index.php',array(
					'menuaction' => 'admin.uicategories.index',
					'appname'    => $appname,
					'global_cats'=> True)),
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Admin'),$file);
			}
		}
	}
	
	/**
	 * populates $GLOBALS['settings'] for the preferences
	 */
	function settings()
	{
		$this->check_set_default_prefs();
		
		$start = array();
		for($i = 0; $i < 24*60; $i += 30)
		{
			if ($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] == 12)
			{
				if (!($hour = ($i / 60) % 12)) 
				{
					$hour = 12;
				}
				$start[$i] = sprintf('%01d:%02d %s',$hour,$i % 60,$i < 12*60 ? 'am' : 'pm');
			}
			else
			{
				$start[$i] = sprintf('%01d:%02d',$i/60,$i % 60);
			}
		}
		$duration = array(0 => lang('not working'));
		for($i = 30; $i <= 24*60; $i += 30)
		{
			$duration[$i] = sprintf('%3.1lf',$i / 60.0).' '.lang('hours');
		}
		foreach($this->weekdays as $day => $label)
		{
			$GLOBALS['settings']['duration_'.$day] = array(
				'type'   => 'select',
				'label'  => lang('Working duration on %1',lang($label)),
				'run_lang' => -1,
				'name'   => 'duration_'.$day,
				'values' => $duration,
				'help'   => 'How long do you work on the given day.',
				'xmlrpc' => True,
				'admin'  => !$this->config['allow_change_workingtimes'],
			);
			$GLOBALS['settings']['start_'.$day] = array(
				'type'   => 'select',
				'label'  => lang('Start working on %1',lang($label)),
				'run_lang' => -1,
				'name'   => 'start_'.$day,
				'values' => $start,
				'help'   => 'At which time do you start working on the given day.',
				'xmlrpc' => True,
				'admin'  => !$this->config['allow_change_workingtimes'],
			);
		}
		return true;	// otherwise prefs say it cant find the file ;-)
	}
	
	/**
	 * Check if reasonable default preferences are set and set them if not
	 *
	 * It sets a flag in the app-session-data to be called only once per session
	 */
	function check_set_default_prefs()
	{
		if ($GLOBALS['egw']->session->appsession('default_prefs_set','projectmanager'))
		{
			return;
		}
		$GLOBALS['egw']->session->appsession('default_prefs_set','projectmanager','set');

		$default_prefs =& $GLOBALS['egw']->preferences->default['projectmanager'];

		$defaults = array(
			'start_1' => 9*60,
			'duration_1' => 8*60,
			'start_2' => 9*60,
			'duration_2' => 8*60,
			'start_3' => 9*60,
			'duration_3' => 8*60,
			'start_4' => 9*60,
			'duration_4' => 8*60,
			'start_5' => 9*60,
			'duration_5' => 6*60,
			'duration_6' => 0,
			'duration_0' => 0,
		);
		foreach($defaults as $var => $default)
		{
			if (!isset($default_prefs[$var]) || $default_prefs[$var] === '')
			{
				$GLOBALS['egw']->preferences->add('projectmanager',$var,$default,'default');
				$need_save = True;
			}
		}
		if ($need_save)
		{
			$GLOBALS['egw']->preferences->save_repository(False,'default');
		}
	}
}
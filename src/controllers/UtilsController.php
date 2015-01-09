<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\controllers;

use Craft;
use craft\app\dates\DateTime;
use craft\app\errors\HttpException;
use craft\app\helpers\IOHelper;
use craft\app\models\LogEntry as LogEntryModel;
use craft\app\requirements\RequirementsChecker;

/**
 * The UtilsController class is a controller that handles various utility related tasks such as displaying server info,
 * php info, log files and deprecation errors in the control panel.
 *
 * Note that all actions in this controller require administrator access in order to execute.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class UtilsController extends BaseController
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritDoc BaseController::init()
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function init()
	{
		// Only admins.
		$this->requireAdmin();
	}

	/**
	 * Server info
	 *
	 * @return null
	 */
	public function actionServerInfo()
	{
		// Run the requirements checker
		$reqCheck = new RequirementsChecker();
		$reqCheck->run();

		$this->renderTemplate('utils/serverinfo', [
			'requirements' => $reqCheck->getRequirements(),
		]);
	}

	/**
	 * PHP info
	 *
	 * @return null
	 */
	public function actionPhpInfo()
	{
		Craft::$app->config->maxPowerCaptain();

		ob_start();
		phpinfo(-1);
		$phpInfo = ob_get_clean();

		$phpInfo = preg_replace(
			[
				'#^.*<body>(.*)</body>.*$#ms',
				'#<h2>PHP License</h2>.*$#ms',
				'#<h1>Configuration</h1>#',
				"#\r?\n#",
				"#</(h1|h2|h3|tr)>#",
				'# +<#',
				"#[ \t]+#",
				'#&nbsp;#',
				'#  +#',
				'# class=".*?"#',
				'%&#039;%',
				'#<tr>(?:.*?)"src="(?:.*?)=(.*?)" alt="PHP Logo" /></a><h1>PHP Version (.*?)</h1>(?:\n+?)</td></tr>#',
				'#<h1><a href="(?:.*?)\?=(.*?)">PHP Credits</a></h1>#',
				'#<tr>(?:.*?)" src="(?:.*?)=(.*?)"(?:.*?)Zend Engine (.*?),(?:.*?)</tr>#',
				"# +#",
				'#<tr>#',
				'#</tr>#'
			],
			[
				'$1',
				'',
				'',
				'',
				'</$1>'."\n",
				'<',
				' ',
				' ',
				' ',
				'',
				' ',
				'<h2>PHP Configuration</h2>'."\n".'<tr><td>PHP Version</td><td>$2</td></tr>'."\n".'<tr><td>PHP Egg</td><td>$1</td></tr>',
				'<tr><td>PHP Credits Egg</td><td>$1</td></tr>',
				'<tr><td>Zend Engine</td><td>$2</td></tr>'."\n".'<tr><td>Zend Egg</td><td>$1</td></tr>',
				' ',
				'%S%',
				'%E%'
			],
			$phpInfo
		);

		$sections = explode('<h2>', strip_tags($phpInfo, '<h2><th><td>'));
		unset($sections[0]);

		$phpInfo = [];
		foreach($sections as $section)
		{
			$heading = substr($section, 0, strpos($section, '</h2>'));

			preg_match_all(
				'#%S%(?:<td>(.*?)</td>)?(?:<td>(.*?)</td>)?(?:<td>(.*?)</td>)?%E%#',
				$section,
				$parts,
				PREG_SET_ORDER
			);

			foreach($parts as $row)
			{
				if (!isset($row[2]))
				{
					continue;
				}
				else if ((!isset($row[3]) || $row[2] == $row[3]))
				{
					$value = $row[2];
				}
				else
				{
					$value = array_slice($row, 2);
				}

				$phpInfo[$heading][$row[1]] = $value;
			}
		}

		$this->renderTemplate('utils/phpinfo', [
			'phpInfo' => $phpInfo
		]);
	}

	/**
	 * Logs
	 *
	 * @param array $variables
	 *
	 * @return null
	 */
	public function actionLogs(array $variables = [])
	{
		Craft::$app->config->maxPowerCaptain();

		if (IOHelper::folderExists(Craft::$app->path->getLogPath()))
		{
			$dateTimePattern = '/^[0-9]{4}\/[0-9]{2}\/[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}/';

			$logFileNames = [];

			// Grab it all.
			$logFolderContents = IOHelper::getFolderContents(Craft::$app->path->getLogPath());

			foreach ($logFolderContents as $logFolderContent)
			{
				// Make sure it's a file.`
				if (IOHelper::fileExists($logFolderContent))
				{
					$logFileNames[] = IOHelper::getFileName($logFolderContent);
				}
			}

			$logEntriesByRequest = [];
			$currentLogFileName = isset($variables['currentLogFileName']) ? $variables['currentLogFileName'] : 'craft.log';

			$currentFullPath = Craft::$app->path->getLogPath().$currentLogFileName;
			if (IOHelper::fileExists($currentFullPath))
			{
				// Different parsing logic for phperrors.log
				if ($currentLogFileName !== 'phperrors.log')
				{
					// Split the log file's contents up into arrays of individual logs, where each item is an array of
					// the lines of that log.
					$contents = IOHelper::getFileContents(Craft::$app->path->getLogPath().$currentLogFileName);

					$requests = explode('******************************************************************************************************', $contents);

					foreach ($requests as $request)
					{
						$logEntries = [];

						$logChunks = preg_split('/^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}) \[(.*?)\] \[(.*?)\] /m', $request, null, PREG_SPLIT_DELIM_CAPTURE);

						// Ignore the first chunk
						array_shift($logChunks);

						// Loop through them
						$totalChunks = count($logChunks);
						for ($i = 0; $i < $totalChunks; $i += 4)
						{
							$logEntryModel = new LogEntryModel();

							$logEntryModel->dateTime = DateTime::createFromFormat('Y/m/d H:i:s', $logChunks[$i]);
							$logEntryModel->level = $logChunks[$i+1];
							$logEntryModel->category = $logChunks[$i+2];

							$message = $logChunks[$i+3];
							$rowContents = explode("\n", $message);

							// Find a few new markers
							$cookieStart = array_search('$_COOKIE=array (', $rowContents);
							$sessionStart = array_search('$_SESSION=array (', $rowContents);
							$serverStart = array_search('$_SERVER=array (', $rowContents);
							$postStart = array_search('$_POST=array (', $rowContents);

							// If we found any of these, we know this is a devMode log.
							if ($cookieStart || $sessionStart || $serverStart || $postStart)
							{
								$cookieStart = $cookieStart ? $cookieStart + 1 : $cookieStart;
								$sessionStart = $sessionStart ? $sessionStart + 1 : $sessionStart;
								$serverStart = $serverStart ? $serverStart + 1 : $serverStart;
								$postStart = $postStart ? $postStart + 1 : $postStart;

								if (!$postStart)
								{
									if (!$cookieStart)
									{
										if (!$sessionStart)
										{
											$start = $serverStart;
										}
										else
										{
											$start = $sessionStart;
										}
									}
									else
									{
										$start = $cookieStart;
									}
								}
								else
								{
									$start = $postStart;
								}

								// Check to see if it's GET or POST
								if (mb_substr($rowContents[0], 0, 5) == '$_GET')
								{
									// Grab GET
									$logEntryModel->get = $this->_cleanUpArray(array_slice($rowContents, 1, $start - 4));
								}

								if (mb_substr($rowContents[0], 0, 6) == '$_POST')
								{
									// Grab POST
									$logEntryModel->post = $this->_cleanUpArray(array_slice($rowContents, 1, $start - 4));
								}

								// We need to do a little more work to find out what element profiling info starts on.
								$tempArray = array_slice($rowContents, $serverStart, null, true);

								$profileStart = false;
								foreach ($tempArray as $key => $tempArrayRow)
								{
									if (preg_match($dateTimePattern, $tempArrayRow))
									{
										$profileStart = $key;
										break;
									}
								}

								// Grab the cookie, session and server sections.
								if ($cookieStart)
								{
									if (!$sessionStart)
									{
										$start = $serverStart;
									}
									else
									{
										$start = $sessionStart;
									}

									$logEntryModel->cookie = $this->_cleanUpArray(array_slice($rowContents, $cookieStart, $start - $cookieStart - 3));
								}

								if ($sessionStart)
								{
									$logEntryModel->session = $this->_cleanUpArray(array_slice($rowContents, $sessionStart, $serverStart - $sessionStart - 3));
								}

								$logEntryModel->server = $this->_cleanUpArray(array_slice($rowContents, $serverStart, $profileStart - $serverStart - 1));

								// We can't just grab the profile info, we need to do some extra processing on it.
								$tempProfile = array_slice($rowContents, $profileStart);

								$profile    = [];
								$profileArr = [];
								foreach ($tempProfile as $tempProfileRow)
								{
									if (preg_match($dateTimePattern, $tempProfileRow))
									{
										if (!empty($profileArr))
										{
											$profile[] = $profileArr;
											$profileArr = [];
										}
									}

									$profileArr[] = rtrim(trim($tempProfileRow), ',');
								}

								// Grab the last one.
								$profile[] = $profileArr;

								// Finally save the profile.
								$logEntryModel->profile = $profile;
							}
							else
							{
								// This is a non-devMode log entry.
								$logEntryModel->message = $rowContents[0];
							}

							// And save the log entry.
							$logEntries[] = $logEntryModel;
						}

						if ($logEntries)
						{
							// Put these logs at the top
							array_unshift($logEntriesByRequest, $logEntries);
						}
					}
				}
				else
				{
					$logEntry = new LogEntryModel();
					$contents = IOHelper::getFileContents(Craft::$app->path->getLogPath().$currentLogFileName);
					$contents = str_replace("\n", "<br />", $contents);
					$logEntry->message = $contents;

					$logEntriesByRequest[] = [$logEntry];
				}
			}

			$this->renderTemplate('utils/logs', [
				'logEntriesByRequest' => $logEntriesByRequest,
				'logFileNames'        => $logFileNames,
				'currentLogFileName'  => $currentLogFileName
			]);
		}
	}

	/**
	 * Deprecation Errors
	 *
	 * @return null
	 */
	public function actionDeprecationErrors()
	{
		Craft::$app->templates->includeCssResource('css/deprecator.css');
		Craft::$app->templates->includeJsResource('js/deprecator.js');

		$this->renderTemplate('utils/deprecationerrors', [
			'logs' => Craft::$app->deprecator->getLogs()
		]);
	}

	/**
	 * View stack trace for a deprecator log entry.
	 *
	 * @return null
	 */
	public function actionGetDeprecationErrorTracesModal()
	{
		$this->requireAjaxRequest();

		$logId = Craft::$app->request->getRequiredParam('logId');
		$log = Craft::$app->deprecator->getLogById($logId);

		return $this->renderTemplate('utils/deprecationerrors/_tracesmodal',
			['log' => $log]
		);
	}

	/**
	 * Deletes all deprecation errors.
	 *
	 * @return null
	 */
	public function actionDeleteAllDeprecationErrors()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		Craft::$app->deprecator->deleteAllLogs();
		Craft::$app->end();
	}

	/**
	 * Deletes a deprecation error.
	 *
	 * @return null
	 */
	public function actionDeleteDeprecationError()
	{
		$this->requirePostRequest();
		$this->requireAjaxRequest();

		$logId = Craft::$app->request->getRequiredPost('logId');

		Craft::$app->deprecator->deleteLogById($logId);
		Craft::$app->end();
	}

	// Private Methods
	// =========================================================================

	/**
	 * @param $arrayToClean
	 *
	 * @return array
	 */
	private function _cleanUpArray($arrayToClean)
	{
		$arrayToClean = implode(' ', $arrayToClean);
		$arrayToClean = '$arrayToClean = ['.str_replace('REDACTED', '"REDACTED"', $arrayToClean).'];';
		eval($arrayToClean);

		foreach ($arrayToClean as $key => $item)
		{
			$arrayToClean[$key] = var_export($item, true);
		}

		return $arrayToClean;
	}

	/**
	 * @param  $backTrace
	 *
	 * @return string
	 */
	private function _formatStackTrace($backTrace)
	{
		$return = [];

		foreach ($backTrace as $step => $call)
		{
			$object = '';

			if (isset($call['class']))
			{
				$object = $call['class'];

				if (is_array($call['args']))
				{
					if (count($call['args']) > 0)
					{
						foreach ($call['args'] as &$arg)
						{
							$this->_getArg($arg);
						}
					}
					else
					{
						$call['args'] = ['array()'];
					}
				}
			}

			$str = '<b>#'.str_pad($step, 3, ' ').'</b>';
			$str .= ($object !== '' ? $object.'->' : '');
			$str .= $call['method'].'('.implode(', ', $call['args']).') called at “'.$call['file'].'” line '.$call['line'];

			$return[] = $str;
		}

		return implode('<br /><br />', $return);
	}

	/**
	 * @param $arg
	 *
	 * @return null
	 */
	private function _getArg(&$arg)
	{
		if (is_object($arg) || is_array($arg))
		{
			$arr = (array)$arg;
			$args = [];

			foreach ($arr as $key => $value)
			{
				if (strpos($key, chr(0)) !== false)
				{
					// Private variable found.
					$key = '';
				}

				if (is_array($value))
				{
					$args[] = '['.$key.'] => '.$this->_getArg($value);
				}
				else
				{
					$args[] = '['.$key.'] => '.(string)$value;
				}


			}

			if (is_object($arg))
			{
				$arg = get_class($arg).' Object ('.implode(',', $args).')';
			}
			else if (is_array($arg) && count($arg) == 0)
			{
				$arg = 'array()';
			}
			else if (is_array($arg) && count($arg) > 0)
			{
				$arg = 'array('.implode(',', $args).')';
			}
		}
		else if (is_array($arg) && count($arg) == 0)
		{
			$arg = '';
		}
	}
}

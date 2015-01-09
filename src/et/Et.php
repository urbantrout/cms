<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\et;

use Craft;
use craft\app\enums\LicenseKeyStatus;
use craft\app\enums\LogLevel;
use craft\app\errors\EtException;
use craft\app\errors\Exception;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\JsonHelper;
use craft\app\models\Et as EtModel;

/**
 * Class Et
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Et
{
	// Properties
	// =========================================================================

	/**
	 * @var string
	 */
	private $_endpoint;

	/**
	 * @var int
	 */
	private $_timeout;

	/**
	 * @var EtModel
	 */
	private $_model;

	/**
	 * @var bool
	 */
	private $_allowRedirects = true;

	/**
	 * @var string
	 */
	private $_userAgent;

	/**
	 * @var string
	 */
	private $_destinationFileName;

	// Public Methods
	// =========================================================================

	/**
	 * @param     $endpoint
	 * @param int $timeout
	 * @param int $connectTimeout
	 *
	 * @return Et
	 */
	public function __construct($endpoint, $timeout = 30, $connectTimeout = 2)
	{
		$endpoint .= Craft::$app->config->get('endpointSuffix');

		$this->_endpoint = $endpoint;
		$this->_timeout = $timeout;
		$this->_connectTimeout = $connectTimeout;

		$this->_model = new EtModel([
			'licenseKey'        => $this->_getLicenseKey(),
			'requestUrl'        => Craft::$app->request->getHostInfo().Craft::$app->request->getUrl(),
			'requestIp'         => Craft::$app->request->getUserIP(),
			'requestTime'       => DateTimeHelper::currentTimeStamp(),
			'requestPort'       => Craft::$app->request->getPort(),
			'localBuild'        => CRAFT_BUILD,
			'localVersion'      => CRAFT_VERSION,
			'localEdition'      => Craft::$app->getEdition(),
			'userEmail'         => Craft::$app->getUser()->getIdentity()->email,
			'track'             => CRAFT_TRACK,
		]);

		$this->_userAgent = 'Craft/'.Craft::$app->getVersion().'.'.Craft::$app->getBuild();
	}

	/**
	 * The maximum number of seconds to allow for an entire transfer to take place before timing out.  Set 0 to wait
	 * indefinitely.
	 *
	 * @return int
	 */
	public function getTimeout()
	{
		return $this->_timeout;
	}

	/**
	 * The maximum number of seconds to wait while trying to connect. Set to 0 to wait indefinitely.
	 *
	 * @return int
	 */
	public function getConnectTimeout()
	{
		return $this->_connectTimeout;
	}

	/**
	 * Whether or not to follow redirects on the request.  Defaults to true.
	 *
	 * @param $allowRedirects
	 *
	 * @return null
	 */
	public function setAllowRedirects($allowRedirects)
	{
		$this->_allowRedirects = $allowRedirects;
	}

	/**
	 * @return bool
	 */
	public function getAllowRedirects()
	{
		return $this->_allowRedirects;
	}

	/**
	 * @param $destinationFileName
	 *
	 * @return null
	 */
	public function setDestinationFileName($destinationFileName)
	{
		$this->_destinationFileName = $destinationFileName;
	}

	/**
	 * @return EtModel
	 */
	public function getModel()
	{
		return $this->_model;
	}

	/**
	 * Sets custom data on the EtModel.
	 *
	 * @param $data
	 *
	 * @return null
	 */
	public function setData($data)
	{
		$this->_model->data = $data;
	}

	/**
	 * @throws EtException|\Exception
	 * @return EtModel|null
	 */
	public function phoneHome()
	{
		try
		{
			$missingLicenseKey = empty($this->_model->licenseKey);

			// No craft/config/license.key file and we can't write to the config folder. Don't even make the call home.
			if ($missingLicenseKey && !$this->_isConfigFolderWritable())
			{
				throw new EtException('Craft needs to be able to write to your “craft/config” folder and it can’t.', 10001);
			}

			if (!Craft::$app->cache->get('etConnectFailure'))
			{
				$data = JsonHelper::encode($this->_model->getAttributes(null, true));

				$client = new \Guzzle\Http\Client();
				$client->setUserAgent($this->_userAgent, true);

				$options = [
					'timeout'         => $this->getTimeout(),
					'connect_timeout' => $this->getConnectTimeout(),
					'allow_redirects' => $this->getAllowRedirects(),
				];

				$request = $client->post($this->_endpoint, $options);

				$request->setBody($data, 'application/json');
				$response = $request->send();

				if ($response->isSuccessful())
				{
					// Clear the connection failure cached item if it exists.
					if (Craft::$app->cache->get('etConnectFailure'))
					{
						Craft::$app->cache->delete('etConnectFailure');
					}

					if ($this->_destinationFileName)
					{
						$body = $response->getBody();

						// Make sure we're at the beginning of the stream.
						$body->rewind();

						// Write it out to the file
						IOHelper::writeToFile($this->_destinationFileName, $body->getStream(), true);

						// Close the stream.
						$body->close();

						return IOHelper::getFileName($this->_destinationFileName);
					}

					$etModel = Craft::$app->et->decodeEtModel($response->getBody());

					if ($etModel)
					{
						if ($missingLicenseKey && !empty($etModel->licenseKey))
						{
							$this->_setLicenseKey($etModel->licenseKey);
						}

						// Cache the license key status and which edition it has
						Craft::$app->cache->set('licenseKeyStatus', $etModel->licenseKeyStatus);
						Craft::$app->cache->set('licensedEdition', $etModel->licensedEdition);
						Craft::$app->cache->set('editionTestableDomain@'.Craft::$app->request->getHostName(), $etModel->editionTestableDomain ? 1 : 0);

						if ($etModel->licenseKeyStatus == LicenseKeyStatus::MismatchedDomain)
						{
							Craft::$app->cache->set('licensedDomain', $etModel->licensedDomain);
						}

						return $etModel;
					}
					else
					{
						Craft::log('Error in calling '.$this->_endpoint.' Response: '.$response->getBody(), LogLevel::Warning);

						if (Craft::$app->cache->get('etConnectFailure'))
						{
							// There was an error, but at least we connected.
							Craft::$app->cache->delete('etConnectFailure');
						}
					}
				}
				else
				{
					Craft::log('Error in calling '.$this->_endpoint.' Response: '.$response->getBody(), LogLevel::Warning);

					if (Craft::$app->cache->get('etConnectFailure'))
					{
						// There was an error, but at least we connected.
						Craft::$app->cache->delete('etConnectFailure');
					}
				}
			}
		}
		// Let's log and rethrow any EtExceptions.
		catch (EtException $e)
		{
			Craft::log('Error in '.__METHOD__.'. Message: '.$e->getMessage(), LogLevel::Error);

			if (Craft::$app->cache->get('etConnectFailure'))
			{
				// There was an error, but at least we connected.
				Craft::$app->cache->delete('etConnectFailure');
			}

			throw $e;
		}
		catch (\Exception $e)
		{
			Craft::log('Error in '.__METHOD__.'. Message: '.$e->getMessage(), LogLevel::Error);

			// Cache the failure for 5 minutes so we don't try again.
			Craft::$app->cache->set('etConnectFailure', true, 300);
		}

		return null;
	}

	// Private Methods
	// =========================================================================

	/**
	 * @return null|string
	 */
	private function _getLicenseKey()
	{
		$licenseKeyPath = Craft::$app->path->getLicenseKeyPath();

		if (($keyFile = IOHelper::fileExists($licenseKeyPath)) !== false)
		{
			return trim(preg_replace('/[\r\n]+/', '', IOHelper::getFileContents($keyFile)));
		}

		return null;
	}

	/**
	 * @param $key
	 *
	 * @return bool
	 * @throws Exception|EtException
	 */
	private function _setLicenseKey($key)
	{
		// Make sure the key file does not exist first. Et will never overwrite a license key.
		if (($keyFile = IOHelper::fileExists(Craft::$app->path->getLicenseKeyPath())) == false)
		{
			$keyFile = Craft::$app->path->getLicenseKeyPath();

			if ($this->_isConfigFolderWritable())
			{
				preg_match_all("/.{50}/", $key, $matches);

				$formattedKey = '';
				foreach ($matches[0] as $segment)
				{
					$formattedKey .= $segment.PHP_EOL;
				}

				return IOHelper::writeToFile($keyFile, $formattedKey);
			}

			throw new EtException('Craft needs to be able to write to your “craft/config” folder and it can’t.', 10001);
		}

		throw new Exception(Craft::t('Cannot overwrite an existing license.key file.'));
	}

	/**
	 * @return bool
	 */
	private function _isConfigFolderWritable()
	{
		return IOHelper::isWritable(IOHelper::getFolderName(Craft::$app->path->getLicenseKeyPath()));
	}
}

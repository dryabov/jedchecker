<?php
/**
 * @package    Joomla.JEDChecker
 *
 * @copyright  Copyright (C) 2017 - 2021 Open Source Matters, Inc. All rights reserved.
 * 			   Copyright (C) 2008 - 2016 compojoom.com . All rights reserved.
 * @author     Daniel Dimitrov <daniel@compojoom.com>
 *             eaxs <support@projectfork.net>
 *
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die('Restricted access');


// Include the rule base class
require_once JPATH_COMPONENT_ADMINISTRATOR . '/models/rule.php';


/**
 * class JedcheckerRulesXMLinfo
 *
 * This class searches all xml manifests for specific tags
 *
 * @since  1.0
 */
class JedcheckerRulesXMLinfo extends JEDcheckerRule
{
	/**
	 * The formal ID of this rule. For example: SE1.
	 *
	 * @var    string
	 */
	protected $id = 'INFO_XML';

	/**
	 * The title or caption of this rule.
	 *
	 * @var    string
	 */
	protected $title = 'COM_JEDCHECKER_INFO_XML';

	/**
	 * The description of this rule.
	 *
	 * @var    string
	 */
	protected $description = 'COM_JEDCHECKER_INFO_XML_DESC';

	/**
	 * Mapping of the plugin title prefix to the plugin group
	 *
	 * @var    string[]
	 */
	protected $pluginsGroupMap = array(
		'button' => 'editors-xtd',
		'editor' => 'editors',
		'smartsearch' => 'finder',
		'twofactorauthentication' => 'twofactorauth'
	);

	/**
	 * Initiates the search and check
	 *
	 * @return    void
	 */
	public function check()
	{
		// Find all XML files of the extension
		// @todo Load only main xml files and extra manifests from package
		$files = JFolder::files($this->basedir, '\.xml$', true, true);

		$manifestFound = false;

		// Iterate through all the xml files
		foreach ($files as $file)
		{
			// Try to find the license
			if ($this->find($file))
			{
				$manifestFound = true;
			}
		}

		if (!$manifestFound)
		{
			$this->report->addError('', JText::_('COM_JEDCHECKER_INFO_XML_NO_MANIFEST'));
		}
	}

	/**
	 * Reads a file and searches for the license
	 *
	 * @param   string  $file  - The path to the file
	 *
	 * @return boolean True if the manifest file was found, otherwise False.
	 */
	protected function find($file)
	{
		$xml = simplexml_load_file($file);

		// Failed to parse the xml file.
		// Assume that this is not a extension manifest
		if (!$xml)
		{
			return false;
		}

		// Check if this is an extension manifest
		// 1.5 uses 'install', 1.6+ uses 'extension'
		if ($xml->getName() !== 'extension')
		{
			return false;
		}

		// Get extension name (element)
		$type = (string) $xml['type'];

		if (isset($xml->element))
		{
			$extension = (string) $xml->element;
		}
		else
		{
			$extension = (string) $xml->name;

			if (isset($xml->files))
			{
				foreach ($xml->files->children() as $child)
				{
					if (isset($child[$type]))
					{
						$extension = (string) $child[$type];
					}
				}
			}
		}

		$extension = strtolower(JFilterInput::getInstance()->clean($extension, 'cmd'));

		if ($type === 'component' && strpos($extension, 'com_') !== 0)
		{
			$extension = 'com_' . $extension;
		}

		if ($type === 'plugin' && isset($xml['group']))
		{
			$extension = 'plg_' . $xml['group'] . '_' . $extension;
		}

		// Load the language of the extension (if any)
		$lang = JFactory::getLanguage();

		// Search for .sys.ini translation file
		$langDir = dirname($file);
		$langTag = 'en-GB';

		$lookupLangDirs = array();

		if (isset($xml->administration->files['folder']))
		{
			$lookupLangDirs[] = trim($xml->administration->files['folder'], '/') . '/language/' . $langTag;
		}

		if (isset($xml->files['folder']))
		{
			$lookupLangDirs[] = trim($xml->files['folder'], '/') . '/language/' . $langTag;
		}

		$lookupLangDirs[] = 'language/' . $langTag;

		if (isset($xml->administration->languages))
		{
			$folder = trim($xml->administration->languages['folder'], '/');

			foreach ($xml->administration->languages->language as $language)
			{
				if (trim($language['tag']) === $langTag)
				{
					$lookupLangDirs[] = trim($folder . '/' . dirname($language), '/');
				}
			}
		}

		if (isset($xml->languages))
		{
			$folder = trim($xml->languages['folder'], '/');

			foreach ($xml->languages->language as $language)
			{
				if (trim($language['tag']) === $langTag)
				{
					$lookupLangDirs[] = trim($folder . '/' . dirname($language), '/');
				}
			}
		}

		$lookupLangDirs[] = '';

		$lookupLangDirs = array_unique($lookupLangDirs);

		foreach ($lookupLangDirs as $dir)
		{
			$langSysFile = $langDir . '/' . ($dir === '' ? '' : $dir . '/') . $langTag . '.' . $extension . '.sys.ini';

			if (is_file($langSysFile))
			{
				$loadLanguage = new ReflectionMethod($lang, 'loadLanguage');
				$loadLanguage->setAccessible(true);
				$loadLanguage->invoke($lang, $langSysFile, $extension);
				break;
			}
		}

		// Get the real extension's name now that the language has been loaded
		$extensionName = $lang->_((string) $xml->name);

		$info[] = JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_XML', $extensionName);
		$info[] = JText::sprintf('COM_JEDCHECKER_INFO_XML_VERSION_XML', (string) $xml->version);
		$info[] = JText::sprintf('COM_JEDCHECKER_INFO_XML_CREATIONDATE_XML', (string) $xml->creationDate);

		$this->report->addInfo($file, implode('<br />', $info));

		// NM3 - Listing name contains “module” or “plugin”
		if (preg_match('/\b(?:module|plugin)\b/i', $extensionName))
		{
			$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_MODULE_PLUGIN', $extensionName));
		}

		if (stripos($extensionName, 'template') !== false)
		{
			$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_RESERVED_KEYWORDS', $extensionName));
		}

		// NM5 - Version in name/title
		if (preg_match('/(?:\bversion\b|\d\.\d)/i', $extensionName))
		{
			$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_VERSION', $extensionName));
		}

		if (stripos($extensionName, 'joomla') === 0)
		{
			$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_JOOMLA', $extensionName));
		}
		elseif (stripos($extensionName, 'joom') !== false)
		{
			$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_JOOMLA_DERIVATIVE', $extensionName));
		}

		$url = (string) $xml->authorUrl;

		if (stripos($url, 'joom') !== false)
		{
			$domain = (strpos($url, '//') === false) ? $url : parse_url(trim($url), PHP_URL_HOST);

			if (stripos($domain, 'joom') !== false)
			{
				// Remove "www." subdomain prefix
				$domain = preg_replace('/^www\./', '', $domain);

				// Approved domains from https://tm.joomla.org/approved-domains.html
				$approvedDomains = file(__DIR__ . '/xmlinfo/approved-domains.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

				if (!in_array($domain, $approvedDomains, true))
				{
					$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_URL_JOOMLA_DERIVATIVE', $url));
				}
			}
		}

		if ($type === 'component' && isset($xml->administration->menu))
		{
			$menuName = $lang->_(trim($xml->administration->menu));

			if ($extensionName !== $menuName)
			{
				$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_ADMIN_MENU', $menuName, $extensionName));
			}
		}

		if ($type === 'plugin')
		{
			$parts = explode(' - ', $extensionName, 2);
			$extensionNameGroup = isset($parts[1]) ? strtolower(preg_replace('/\s/', '', $parts[0])) : false;
			$group = (string) $xml['group'];

			if ($extensionNameGroup !== $group && $extensionNameGroup !== str_replace('-', '', $group)
				&& !(isset($this->pluginsGroupMap[$extensionNameGroup]) && $this->pluginsGroupMap[$extensionNameGroup] === $group)
			)
			{
				$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_PLUGIN_FORMAT', $extensionName));
			}
		}

		// All checks passed. Return true
		return true;
	}
}

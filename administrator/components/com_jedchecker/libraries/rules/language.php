<?php
/**
 * @package    Joomla.JEDChecker
 *
 * @copyright  Copyright (C) 2017 - 2021 Open Source Matters, Inc. All rights reserved.
 *             Copyright (C) 2008 - 2016 compojoom.com . All rights reserved.
 * @author     Denis Ryabov <denis@mobilejoomla.com>
 *
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die('Restricted access');


// Include the rule base class
require_once JPATH_COMPONENT_ADMINISTRATOR . '/models/rule.php';


/**
 * class JedcheckerRulesLanguage
 *
 * This class validates language ini file
 *
 * @since  3.0
 */
class JedcheckerRulesLanguage extends JEDcheckerRule
{
	/**
	 * The formal ID of this rule. For example: SE1.
	 *
	 * @var    string
	 */
	protected $id = 'LANG';

	/**
	 * The title or caption of this rule.
	 *
	 * @var    string
	 */
	protected $title = 'COM_JEDCHECKER_LANG';

	/**
	 * The description of this rule.
	 *
	 * @var    string
	 */
	protected $description = 'COM_JEDCHECKER_LANG_DESC';

	/**
	 * Initiates the search and check
	 *
	 * @return    void
	 */
	public function check()
	{
		// Find all INI files of the extension (in the format tag.extension.ini or tag.extension.sys.ini)
		$files = JFolder::files($this->basedir, '^[a-z]{2,3}-[A-Z]{2}\.\w+(?:\.sys)?\.ini$', true, true);

		// Iterate through all the ini files
		foreach ($files as $file)
		{
			// Try to validate the file
			$this->find($file);
		}
	}

	/**
	 * Reads and validates an ini file
	 *
	 * @param   string  $file  - The path to the file
	 *
	 * @return boolean True on success, otherwise False.
	 */
	protected function find($file)
	{
		$lines = file($file);
		$nLines = count($lines);
		$keys = array();

		for ($lineno = 0; $lineno < $nLines; $lineno++)
		{
			$startLineno = $lineno + 1;
			$line = trim($lines[$lineno]);

			if ($lineno === 0 && strncmp($line, "\xEF\xBB\xBF", 3) === 0)
			{
				if (isset($line[3]) && strpos(";\n\r", $line[3]) === false)
				{
					$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_BOM_FOUND'), $startLineno);
				}
				else
				{
					$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_BOM_FOUND'), $startLineno);
				}

				$line = substr($line, 3);
			}

			if ($line === '' || $line[0] === ';' || $line[0] === '[')
			{
				continue;
			}

			if ($line[0] === '#')
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_INCORRECT_COMMENT'), $startLineno, $line);
				continue;
			}

			if (strpos($line, '=') === false)
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_WRONG_LINE'), $startLineno, $line);
				continue;
			}

			list ($key, $value) = explode('=', $line, 2);

			// Validate key
			$key = rtrim($key);

			if ($key === '')
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_KEY_EMPTY'), $startLineno, $line);
				continue;
			}

			if (strpos($key, ' ') !== false)
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_KEY_WHITESPACE'), $startLineno, $line);
				continue;
			}

			if (strpbrk($key, '{}|&~![()^"') !== false)
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_KEY_INVALID_CHARACTER'), $startLineno, $line);
				continue;
			}

			if (in_array($key, array('null', 'yes', 'no', 'true', 'false', 'on', 'off', 'none'), true))
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_KEY_RESERVED'), $startLineno, $line);
				continue;
			}

			if (preg_match('/[\x00-\x1F\x80-\xFF]/', $key))
			{
				$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_KEY_NOT_ASCII'), $startLineno, $line);
			}

			if ($key !== strtoupper($key))
			{
				$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_KEY_NOT_UPPERCASE'), $startLineno, $line);
			}

			if (isset($keys[$key]))
			{
				$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_LANG_KEY_DUPLICATED', $keys[$key]), $startLineno, $line);
			}
			else
			{
				$keys[$key] = $startLineno;
			}

			// Validate value
			$value = ltrim($value);

			// Parse multiline values
			while (!preg_match('/^((?>\'(?>[^\'\\\\]+|\\\\.)*\'|"(?>[^"\\\\]+|\\\\.)*"|[^\'";]+)*)(;.*)?$/', $value, $matches))
			{
				if ($lineno + 1 >= $nLines)
				{
					break;
				}

				$lineno++;
				$chunk = "\n" . trim($lines[$lineno]);
				$line .= $chunk;
				$value .= $chunk;
			}

			if (!isset($matches[0]))
			{
				$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_TRANSLATION_ERROR'), $startLineno, $line);
				continue;
			}

			$value = trim($matches[1]);

			if ($value === '""')
			{
				$this->report->addInfo($file, JText::_('COM_JEDCHECKER_LANG_TRANSLATION_EMPTY'), $startLineno, $line);
				continue;
			}

			if (strlen($value) < 2 || $value[0] !== '"' || substr($value, -1) !== '"')
			{
				$this->report->addError($file, JText::_('COM_JEDCHECKER_LANG_TRANSLATION_QUOTES'), $startLineno, $line);
				continue;
			}

			$value = substr($value, 1, -1);

			if (strpos($value, '"_QQ_"') !== false)
			{
				$this->report->addCompat($file, JText::_('COM_JEDCHECKER_LANG_QQ_DEPRECATED'), $startLineno, $line);
			}

			$value = str_replace('"_QQ_"', '\"', $value);

			if (preg_match('/[^\\\\]"/', $value))
			{
				$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_UNESCAPED_QUOTE'), $startLineno, $line);
			}

			if (strpos($value, '${') !== false)
			{
				$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_VARIABLE_REF'), $startLineno, $line);
			}

			// The code below detects incorrect format of numbered placeholders (e.g. "%1s" instead of "%1$s")

			// Count all placeholders in the string
			$countAll = preg_match_all('/(?<=^|[^%])%(?=[-+0 ]?\w)/', $value);

			// Count numbered placeholders in the string (e.g. "%1s")
			$count = preg_match_all('/(?<=^|[^%])%(\d+)\w/', $value, $matches);

			if ($count === $countAll && $count > 1)
			{
				$maxNumber = 0;

				foreach ($matches as $match)
				{
					$maxNumber = max($maxNumber, (int) $match[1]);
				}

				// If placeholder numbers form a sequence, the maximal value is equal to the number of elements
				if ($maxNumber === $count)
				{
					$this->report->addWarning($file, JText::_('COM_JEDCHECKER_LANG_INCORRECT_ARGNUM'), $startLineno, $line);
				}
			}
		}

		// All checks passed. Return true
		return true;
	}
}

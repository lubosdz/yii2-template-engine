<?php
/**
* Template rendering engine for PHP framework Yii ver. 2 (https://www.yiiframework.com/).
* Fast, secure, flexible, extensible with zero configuration and no dependencies, similar to Blade or Twig.
*
* Copyright (c) Lubos Dzurik (https://github.com/lubosdz), distributed under BSD license (free personal & commercial usage)
*
* Example:
* --------
*   "Your order #{{order.id}} has been accepted on {{ order.created_datetime | date }}."
* will translate into:
*   "Your order #123 has been accepted on 20.08.2023."
*
* Supports:
* ---------
*  - IF .. ELSEIF .. ELSE .. ENDIF
*  - FOR ... ELSEFOR .. ENDFOR
*  - SET variable = expression
*  - IMPORT subtemplate
*  - dynamic custom directives
*  - built-in most common directives and date/time formatter
*
* Github repos:
*  - https://github.com/lubosdz/yii2-template-engine
*  - https://github.com/lubosdz/html-templating-engine (alternative without dependencies and without yii2 features)
*/

namespace lubosdz\yii2;

use Yii;
use yii\web\HttpException;
use yii\helpers\StringHelper;

class TemplateEngine
{
	/** @var array List of placeholders */
	protected $resPlaceholders = [];

	/** @var array of values and objects to be inserted as values */
	protected $resValues = [];

	/** @var array Map of placeholder -> value */
	protected $resMap = []; //

	/** @var string Parsed HTML source */
	protected $resHtml;

	/** @var array List of dynamic directives */
	protected $dynDir = [];

	/**
	* @var bool Whether to log parsing errors
	*/
	protected $logErrors = true;

	/**
	* @var bool|string Whether to remove placeholder (replace with empty string) if no replacement value found
	*  - if set as a string, such a string will be used as a replacement value
	*  - if set as a boolean TRUE, then empty string "" will be used as a replacement value
	*  - if set as a boolean FALSE, no replacement occurs and original placeholder will render, e.g. {{ missing_value }}
	*/
	protected $forceReplace = false;

	/**
	* @var string Argument separator, defaults to semicolon [;]
	*/
	protected $argSeparator = ';';

	/**
	* @var string Absolute path to directory with templates
	*/
	protected $dirTemplates;

	/**
	* @var array Variables parsed & evaluated by SET directive
	*/
	protected $globalVars = [];

	/**
	* @var array List of processing errors, will be logged automatically
	*/
	protected $errors = [];

	/**
	* @var \yii\i18n\Formatter Just a shorthand for quick access
	*/
	protected $formatter;

	/**
	* Constructor
	*/
	public function __construct()
	{
		$this->formatter = Yii::$app->formatter;

		// log errors at the end
		register_shutdown_function(function(){
			if ($this->logErrors && $this->errors) {
				Yii::error("Found ".count($this->errors)." errors while processing HTML template ["
					.StringHelper::truncate($this->resHtml, 100) // we have no other identification
					."]: \n".implode("\n", $this->errors), 'app.template');
			}
		});
	}

	/**
	* Return collected errors
	*/
	public function getErrors()
	{
		return $this->errors;
	}

	/**
	* Remove collected errors
	* @return TemplateEngine
	*/
	public function clearErrors()
	{
		$this->errors = [];
		return $this;
	}

	/**
	* Add an error
	* @param string $txt Error string
	* @return TemplateEngine
	*/
	protected function addError($txt)
	{
		if ($this->logErrors) {
			$this->errors[] = $txt;
		}
		return $this;
	}

	/**
	* Set whether errors should be logged after processing the output
	* @param bool $log
	* @return TemplateEngine
	*/
	public function setLogErrors($log)
	{
		$this->logErrors = $log ? true : false;
		return $this;
	}

	/**
	* @param bool|string $replace Whether to remove placeholders if variable not defined or error occurs
	* 		 If bool TRUE, missed splaceholder will have value NULL, if string "...." then missed placeholders will become "...."
	* @return TemplateEngine
	*/
	public function setForceReplace($replace)
	{
		$this->forceReplace = $replace;
		return $this;
	}

	/**
	* Set the argument separator, defaults to semicolon [;]
	* @param string $sep
	* @return TemplateEngine
	*/
	public function setArgSeparator($sep)
	{
		$this->argSeparator = $sep;
		return $this;
	}

	/**
	* Set path to directory with templates
	* @param string $path Abs. path to valid directory
	* @return TemplateEngine
	*/
	public function setDirTemplates($path)
	{
		if (!is_dir($path)) {
			throw new HttpException(404, Yii::t('app', 'Invalid directory "{path}".', ['path' => $path]));
		}
		$this->dirTemplates = realpath($path);
		return $this;
	}

	/**
	* Return path to directory with templates
	*/
	public function getDirTemplates()
	{
		return $this->dirTemplates;
	}

	/**
	* Set arbitrary dynamic directive, e.g. this is {{ output | coloredText(yellow) }}
	* @param string $name
	* @param callable $callable
	* @return TemplateEngine
	*/
	public function setDirective($name, $callable)
	{
		$this->dynDir[$name] = $callable;
		return $this;
	}

	/**
	* Return resources generated while processing output
	* - list of generated final map [placeholder => replaced value]
	* - list of collected placeholders [placeholder => directive],
	* - list of valid params to be replaced
	* - html raw input - source HTML template before processing
	* @param bool $reset Reset / clear resources after being returned
	* @return array($map, $placeholders, $values, $htmlRaw)
	*/
	public function getResources($reset = true)
	{
		$out = [
			$this->resMap,
			$this->resPlaceholders,
			$this->resValues,
			$this->resHtml
		];

		if ($reset) {
			$this->resMap = $this->resPlaceholders = $this->resValues = [];
			$this->resHtml = null;
		}

		return $out;
	}

	/**
	* Replace placeholders
	* Note: this is recursively called method
	* @param string $html HTML to be processed. This can be also path alias starting with "@" e.g. "@app/templates/invoice.html"
	* @param array $params List of params - AR objects, arrays or non-numeric scalars
	* @param bool $resetGlobalVars Clear already parsed global directives
	* @param bool $cacheRes Whether to cache or not resources, false only for processing {{ for }} to process correctly repeated sections
	*/
	public function render($html, array $values = [], $resetGlobalVars = true, $cacheRes = true)
	{
		if ('@' == substr($html, 0, 1)) {
			// load HTML from path alias, file must exist
			$path = Yii::getAlias($html);
			if (!is_file($path)) {
				throw new HttpException(404, Yii::t('app', 'Template file not found in "{path}".', ['path' => $path]));
			}
			// set parent directory
			$this->setDirTemplates(dirname($path));
			// load content to process
			$html = file_get_contents($path);
		}

		if (null === $this->resHtml) {
			// keep only the very first supplied HTML source
			$this->resHtml = $html;
		}

		if ($html) {
			if ($resetGlobalVars) {
				$this->globalVars = [];
			}

			$placeholders = $this->collectPlaceholders($html);
			if ($placeholders && $cacheRes) {
				$this->resPlaceholders += $placeholders;
			}

			$values = $this->collectValues($placeholders, $values);
			if ($values && $cacheRes) {
				$this->resValues += $values;
			}

			$map = $this->generateMap($placeholders, $values);
			if ($map && $cacheRes) {
				$this->resMap += $map;
			}

			$html = strtr($html, $map);
		}

		return $html;
	}

	/**
	* Return list of placeholders inside supplied HTML
	* @param string $html
	*/
	protected function collectPlaceholders($html) : array
	{
		$all = [];
		$offset = 0;

		while (false !== ($pos1 = strpos($html, '{{', $offset))) {
			$pos2 = strpos($html, '}}', $pos1);
			if ($pos2 && $pos2 > $pos1) {
				$placeholder = substr($html, $pos1, $pos2 - $pos1 + 2);

				if (preg_match("/^{{\s*if\b(.+)}}/i", $placeholder)) {
					// parse {{ IF .. ELSEIF .. ELSE .. ENDIF }}
					$pos2 = stripos($html, 'endif', $pos1);
					if ($pos2 && ($pos2 = stripos($html, '}}', $pos2))) {
						$placeholder = substr($html, $pos1, $pos2 - $pos1 + 2);
					}
					$trimPattern = " \n"; // keep curly brackets for easier parsing
				} elseif (preg_match("/^{{\s*for\b(.+)}}/i", $placeholder)) {
					// parse {{ FOR .. ELSEFOR .. ENDFOR }}
					$pos2 = stripos($html, 'endfor', $pos1);
					if ($pos2 && ($pos2 = stripos($html, '}}', $pos2))) {
						$placeholder = substr($html, $pos1, $pos2 - $pos1 + 2);
					}
					$trimPattern = " \n"; // keep curly brackets for easier parsing
				} elseif (preg_match("/^{{\s*set\b(.+)=(.+)/i", $placeholder)) {
					// parse {{ SET variable = expression }}
					$trimPattern = " {}\n";
				} else {
					// any placeholder e.g. "order.id", "variableName" or "order.created | date"
					$trimPattern = " {}\n";
				}

				 // normalize - wysiwyg may insert sometimes entity instead of whitespace
				$val = str_replace('&nbsp;', ' ', $placeholder);
				$all[$placeholder] = trim($val, $trimPattern);
				$offset = $pos2 + 2;
			} else {
				$offset = $pos1 + 2;
			}
		}

		return $all;
	}

	/**
	* Return list of values for placeholder - objects (AR), arrays, scalars
	* @param array $placeholders
	* @param array $params
	*/
	protected function collectValues(array $placeholders, array $params) : array
	{
		$outModels = $outScalarsArrays = [];

		foreach ($params as $key => $model) {
			if ($model instanceOf \yii\base\Model) {
				// extract objects with attributes (active records & model forms)
				$name = is_numeric($key) ? self::getShortClassname($model) : strtolower($key);
				$outModels[$name] = $model;
			} elseif (!is_numeric($key) && (is_scalar($model) || is_array($model))) {
				// primitives with named keys, e.g. 'topLabel' => 'Client name'
				$outScalarsArrays[$key] = $model;
			} elseif ($model === null) {
				// register also null values, which will be replaced later
				$outScalarsArrays[$key] = null;
			}
		}

		return $outModels + $outScalarsArrays;
	}

	/**
	* Return map for translating the placeholders
	* @param array $placeholders
	* @param array $paramsValid
	*/
	protected function generateMap(array $placeholders, array $paramsValid) : array
	{
		$map = [];

		foreach ($placeholders as $place => $directives) {
			$val = null; // default NULL - means not replaced (e.g. expression syntax error, invalid variable name etc.)
			$paramsValid = array_merge($paramsValid, $this->globalVars);

			if (preg_match("/^{{\s*if\b/i", $directives)) {
				$val = $this->parseAndEvalIf($directives, $paramsValid);
			} elseif (preg_match("/^{{\s*for\b/i", $directives)) {
				$val = $this->parseAndEvalFor($directives, $paramsValid);
			} elseif (preg_match("/^\s*set\b/i", $directives)) {
				$val = $this->parseAndEvalSet($directives, $paramsValid);
			} elseif (preg_match("/^\s*import\b/i", $directives)) {
				$val = $this->parseAndEvalImport($directives, $paramsValid);
			} else {
				$directives = explode('|', $directives);
				foreach ($directives as $directive) {
					$val = $this->processDirective($directive, $paramsValid, $val);
				}
			}

			// NULL means no replacement occured (usually error) - keep original placeholder for quick identification
			// normally is returned empty string "" for empty values or 0 for numeric
			if (null !== $val) {
				$map[$place] = $val;
			} elseif (false !== $this->forceReplace) {
				$map[$place] = is_bool($this->forceReplace) ? "" : $this->forceReplace;
			}
		}

		return $map;
	}

	/**
	* Main method for processing single template directive
	* @param string $directive e.g. model.attribute or supported function e.g. "upper"
	* @param array $paramsValid Supplied AR models, scalars, arrays
	* @param string $val Current value
	*/
	protected function processDirective($directive, array $paramsValid, $val = null)
	{
		// e.g. "order.price|round(2)" or "car.car_title"
		$args = explode('(', trim($directive));
		$directive = array_shift($args);
		$directive = trim($directive, ' .'); // fix spaces between arguments e.g. "round  (2)" and remove trailing/leading dots
		$args = $args ? trim(implode($this->argSeparator, $args), "() \n{$this->argSeparator}") : null;

		if (false !== strpos($directive, '.')) {
			// e.g. model.attribute or model.related.attribute
			$tmp = $this->getValue($directive, $paramsValid);
			if ($tmp !== null) {
				$val .= ' '.$tmp;
				$val = trim($val);
			}
		} elseif (array_key_exists($directive, $paramsValid)) {
			// replace scalar value
			$val = $paramsValid[$directive];
		} elseif (method_exists($this, 'dir_'.$directive)) {
			// implemented functions / directives
			if ($args !== null) {
				// parse arguments, semicolon is argument separator, since it occurs less in common strings
				$args = explode($this->argSeparator, $args);
				$args = array_map('trim', $args);
				// @todo - replace with variadics (since PHP 5.6), currently we support up to 3 arguments
				if (1 == count($args)) {
					$val = call_user_func([$this, 'dir_'.$directive], $val, $args[0]);
				} elseif (2 == count($args)) {
					$val = call_user_func([$this, 'dir_'.$directive], $val, $args[0], $args[1]);
				} else {
					$val = call_user_func([$this, 'dir_'.$directive], $val, $args[0], $args[1], $args[2]);
				}
			} else {
				$val = call_user_func([$this, 'dir_'.$directive], $val);
			}
		} elseif (array_key_exists($directive, $this->dynDir)) {
			$callable = $this->dynDir[$directive];
			if (is_callable($callable)) {
				$val = call_user_func($callable, $val, $args);
			}
		} /* elseif (function_exists($directive)) {
			$val = call_user_func($directive, $val, $args);
			// works, but not supported due to security considerations
			// all supported functions should be simply implemented
		} */
		elseif ($directive) {
			$this->addError('Unsupported directive ['.$directive.']');
		}

		return $val;
	}

	/**
	* Return attribute or array value
	* @param string $directive e.g. model.attribute or model.related.attribute or array.key
	* @param array $paramsValid scalars, models, arrays
	*/
	protected function getValue($directive, array $paramsValid)
	{
		$val = null;

		if (array_key_exists($directive, $paramsValid)) {
			// quick lookup directly in values tree
			// here we also resolve conflicting keys by prioritizing keys with shallower depth within the values tree
			$val = $paramsValid[$directive];
		}else{
			$chain = explode('.', $directive);
			$model = $array = null;

			while ($attr = array_shift($chain)) {
				$attrLower = strtolower($attr);
				if (!$model && !$array) {
					if (array_key_exists($attrLower, $paramsValid) && is_object($paramsValid[$attrLower])) {
						$model = $paramsValid[$attrLower];
					} elseif (array_key_exists($attr, $paramsValid) && is_array($paramsValid[$attr])) {
						$array = $paramsValid[$attr];
					}
				} elseif ($model && $model->hasProperty($attr)) {
					$val = $model->{$attr};
					// ensure deep-chaining
					if (is_object($val)) {
						$model = $val; // e.g. related model
					} elseif (is_array($val)) {
						$array = $val;
					}
				} elseif ($array && array_key_exists($attr, $array)) {
					$val = $array[$attr];
					// ensure deep-chaining
					if (is_object($val)) {
						$model = $val;
					} elseif (is_array($val)) {
						$array = $val;
					}
				} else {
					// invalid attribute - ensure NULL value as a replacement candidate
					$val = null;
				}
			}
		}

		return $val;
	}

	/**
	* Return short class name extracted from fully qualified namespace
	* @param object|string $ns Namespace or object
	* @param bool $lower
	*/
	protected static function getShortClassname($ns, $lower = true)
	{
		if (is_object($ns)) {
			$ns = get_class($ns);
		}
		$name = basename(str_replace('\\', '/', $ns));
		if ($lower) {
			$name = strtolower($name);
		}
		return $name;
	}

	/**
	* Parse and evaluate {{ IF .. ELSEIF .. ENDIF }} statement
	* @param string $directive
	* @param array $paramsValid
	*/
	protected function parseAndEvalIf($directive, array $paramsValid)
	{
		$val = null; // placeholder won't be replaced if condition invalid
		$parts = preg_split("/{{\s*(if\b|\belseif\b|\belse\b|endif)/i", $directive);

		foreach ($parts as $part) {
			if ($part && false !== strpos($part, '}}')) {
				list($condition, $html) = explode('}}', $part, 2);
				if (trim( (string) $condition) != '') {
					$val = $php = ''; // ensure placeholder will be replaced even on false condition
					$isTrue = false;
					try {
						$php = $this->translateExpression($condition, $paramsValid);
						$php = 'return '.$php.';';
						ob_start();
						$isTrue = eval($php);
						$err = ob_get_clean();
						if ($err) {
							$this->addError(strip_tags($err));
							// don't replace placeholder - usually missing (undefined) variable inside IF condition e.g. "Use of undefined constant abc - assumed 'abc'"
							return null;
						}
					} catch (\Throwable $e) {
						$this->addError("[if] Parse error: {$e->getMessage()} on line {$e->getLine()} in expression [{$php}].\nFull directive:\n{$directive}\n");
						return null; // don't replace placeholder - this is error
					}
				} else {
					$isTrue = true; // last ENDIF has no condition, will always apply
				}
				if ($isTrue && $html) {
					$val = $this->render(trim($html), $paramsValid, false);
					break;
				}
			}
		}

		return $val;
	}

	/**
	* Return IF condition with translated variable values for further evaluation
	* @param string $cond e.g. "order.id > 100"
	* @param array $paramsValid
	*/
	protected function translateExpression($expr, array $paramsValid)
	{
		$expr = trim($expr);
		$map = [];

		if ($expr) {
			// check single variable key e.g. "user" or "user_name"
			if (array_key_exists($expr, $paramsValid)) {
				// key hit expression e.g. "{{ if users }}"
				return empty($paramsValid[$expr]) ? 'false' : 'true';
			} elseif (!is_numeric($expr) && strlen($expr) < 30 && preg_match('/^\w+$/', $expr)) {
				// Here we assume single non-numeric key up to 30 chars, which does not exist, therefore no further processing needed.
				// The pattern/condition should meet requirements for naming the PHP constants (more tuning here possibly needed).
				// This prevents from PHP eval error "Undefined constant ...".
				// We ignore valid patterns such as:
				//  - related attributes with syntax "user.name",
				//  - formulas with math operators +/- ..
				//  - strings containing spaces and/or non a-Z chars
				return 'false';
			}
		}

		// collect attribute / array values
		preg_match_all("/([\w]+\.[\w]+)/i", $expr, $match);
		if (!empty($match[0])) {
			foreach ($match[0] as $directive) {
				$val = (string) $this->processDirective($directive, $paramsValid);
				if (!is_numeric($val) || trim($val) === "" || '0' === substr($val, 0, 1)) {
					$val = '"'.trim( (string) $val, '"').'"'; // fix eval crash: null -> ""
				}
				if (false === strpos($directive, '{{')) {
					$map["/\b".$directive."\b/"] = $val;
				}
			}
		}

		// collect scalars
		foreach($paramsValid as $key => $val){
			if (!is_object($val) && !is_array($val)) {
				if (trim( (string) $val) !== "") {
					if (!is_numeric($val) || '0' === substr($val, 0, 1)) {
						// special case - numeric starting with zero "0" are also strings
						$val = '"'.trim( (string) $val, '"').'"'; // fix eval crash: null -> ""
					}
				} else {
					// ugly & unreliable workaround - fix NULL and "" to avoid f**king "non-numeric value encountered" since 7.1
					// NULL & empty strings should have been treated just like before 7.1 !!! or only under strict mode !!
					if (preg_match("/[\+\-\*\/\>\<\=]+/", $expr)) {
						// we have probably math formula - cast to a number
						$val = floatval($val); // fix eval crash: null -> 0 in formulas
					} else {
						// probably not formula - cast to string
						$val = '"'.trim( (string) $val, '"').'"'; // fix eval crash: null -> "" for strings
					}
				}
				if (false === strpos($key, '{{')) {
					$map["/\b".$key."\b/"] = $val;
				}
			}
		}

		// translate strings inside condition without partially parsed placeholders, which may corrupt multiline regex
		if ($map) {
			$expr = preg_replace(array_keys($map), $map, $expr);
		}

		return $expr;
	}

	/**
	* Parse and evaluate {{ FOR .. ENDFOR }} statement
	* @param string $directive
	* @param array $paramsValid
	*/
	protected function parseAndEvalFor($directive, array $paramsValid)
	{
		$val = null;
		$parts = preg_split("/{{\s*(elsefor\b|\bendfor\b)/i", trim($directive));

		if (!empty($parts[0]) && preg_match("/^{{\s*for\s+(.+)\s+in\s+(.+)\s*}}/i", $parts[0], $match)) {

			list(, $varName, $itemsName) = $match;
			$itemsName = trim($itemsName);
			$htmlFor = trim(explode('}}', $parts[0], 2)[1]);
			$htmlElseFor = (3 == count($parts)) ? trim($parts[1], " \t\n\r\0\x0B{}") : '';

			if (!empty($paramsValid[$itemsName]) && is_array($paramsValid[$itemsName])) {

				$items = $paramsValid[$itemsName];
				$count = count($items);
				$index = 1;

				foreach ($items as $item) {
					// loop variables - similar to twig, https://twig.symfony.com/doc/3.x/tags/for.html
					$paramsValid['loop'] = [
						'index' => $index,           // 1-based iteration counter
						'index0' => $index - 1,      // 0-based iteration counter
						'length' => $count,          // total number of items/iterations
						'first' => $index == 1,      // true on first iteration
						'last' => $index == $count,  // true on last iteration
					];

					$paramsValid[$varName] = $item;
					$val .= "\n".$this->render($htmlFor, $paramsValid, false, false);
					++$index;
				}

				$val = trim( (string) $val);

			} elseif ($htmlElseFor) {
				$val .= $this->render($htmlElseFor, $paramsValid, false);
			}
		}

		return $val;
	}

	/**
	* Parse and evaluate statement e.g. "{{ SET variable = expression }}"
	* Notes:
	* 	- we do not support shorthand expressions like "sum += number", due to difficult parsing
	* 	- no need to initiate non-existing variables on left side - "amount", so following is valid: "amount = amount + item.quantity"
	* 	- no multiple assignments, only one SET expression per brackets {{ set ... }}
	* 	- all SET variables become globally accessible in any following processed code, unless forcibly reset
	* @param string $directive e.g. "{{ SET variable = expression }}"
	* @param array $paramsValid
	*/
	protected function parseAndEvalSet($directive, array $paramsValid)
	{
		$parts = explode('=', $directive, 2);

		if (!empty($parts[1])) {
			$varName = trim(preg_replace("/^set\b/i", '', $parts[0]));
			$expression = trim($parts[1]);
			$result = $php = null;

			if (!array_key_exists($varName, $this->globalVars)) {
				$this->globalVars[$varName] = null;
				$paramsValid[$varName] = null;
			}

			try {
				$php = $this->translateExpression($expression, $paramsValid);
				$php = 'return '.$php.';';
				ob_start();
				$result = eval($php);
				$err = ob_get_clean();
				if ($err) {
					$this->addError(strip_tags($err));
				}
			} catch (\Throwable $e) {
				$this->addError("[set] Parse error: {$e->getMessage()} on line {$e->getLine()} in expression [{$php}].\nFull directive:\n{$directive}\n");
				return null; // don't replace placeholder on parsing error
			}

			$this->globalVars[$varName] = $result;
		}

		// SET has no output, return empty string instead of NULL to ensure placeholder will be replaced
		return '';
	}

	/**
	* Import partial template from current template directory
	* @param string $file The file name or relative path to valid template directory e.g. "partial.html" or in subdirectory "partial/header.html".
	* @param array $paramsValid
	*/
	protected function parseAndEvalImport($directive, array $paramsValid)
	{
		$html = '';
		$file = preg_split("/\s+/", $directive);

		if (!empty($file[1])) {
			// validate against template directory
			$file = trim($file[1]);
			if (!$this->dirTemplates) {
				throw new HttpException(500, Yii::t('app', 'Please set template directory.'));
			}
			$path = $this->dirTemplates .'/'. ltrim($file, ' \/.');
			if (false === strpos($path, basename($this->dirTemplates))) {
				throw new HttpException(500, Yii::t('app', 'Template path "{path}" may not point out of template directory "{dir}".', [
					'path' => $path,
					'dir' => $this->dirTemplates,
				]));
			} elseif (!is_file($path)) {
				throw new HttpException(404, Yii::t('app', 'Template file not found in "{path}".', ['path' => $path]));
			}
			$html = file_get_contents($path);
			$html = $this->render($html, $paramsValid, false);
		}

		return $html;
	}

	/**
	* Return true if supplied valid date or time string, including timestamp
	* @param int|string $val e.g. 123 or "April 10, 2022", "2023-12-31", "31/12/2023" etc. but not "......" (placeholder) nor "April"
	*/
	protected static function isDatetimeString($val)
	{
		if (!$val) {
			return false; // 0, null, "", false
		} elseif (preg_match("/\d+/", $val) && (is_numeric($val) || strtotime($val))) {
			// valid datetime string must contain at least one digit - either timestamp or date/time string
			// discovered strange PHP bug (?): strtotime('......') -> 1689019109 (current timestamp)
			return true;
		}
		return false;
	}

	####################################################################
	#  Supported global directives - prefix "dir_*"
	#  E.g. template directive {{ myFunction(arg1) }} will look for method dir_myFunction(arg1)
	####################################################################

	/**
	* Return current timestamp e.g. "{{ now | date }}"
	* @param string $dummy Just args placeholder, not in use
	* @param int $shiftTime Optionally shift returned time relatively to current time, e.g. "now(+7200)" will return +2 hours
	*/
	protected function dir_now($dummy = "", $shiftTime = 0)
	{
		$ts = time();
		if ($shiftTime) {
			$ts += intval($shiftTime);
		}
		return $ts;
	}

	/**
	* Return formatted today's date e.g. "{{ today }}"
	* @param string $dummy Just args placeholder, not in use
	* @param int|float $shiftDays e.g. "today(+14)" will generate formatted date +14 days
	* @param string $format short|medium|long
	*/
	protected function dir_today($dummy = "", $shiftDays = 0, $format = null)
	{
		$ts = time();
		if ($shiftDays) {
			$ts += (86400 * $shiftDays);
		}
		$format = (null == $format) ? 'medium' : $format;
		return $this->formatter->asDate($ts, $format);
	}

	/**
	* Return locally formatted date e.g. "{{ order.datetime_created | date }}"
	* @param int|string $val Timestamp or date string
	* @param string $format e.g. medium|short|long
	*/
	protected function dir_date($val, $format = null)
	{
		if (!self::isDatetimeString($val)) {
			return $val;
		}
		$ts = is_numeric($val) ? (int) $val : $this->formatter->asTimestamp($val);
		$format = (null == $format) ? 'medium' : $format;
		return $this->formatter->asDate($ts, $format);
	}

	/**
	* Return locally formatted time e.g. "{{ order.datetime_created | time }}"
	* @param int|string $val Timestamp or date string
	* @param string $format e.g. medium|short|long
	*/
	protected function dir_time($val, $format = null)
	{
		if (!self::isDatetimeString($val)) {
			return $val;
		}
		$ts = is_numeric($val) ? (int) $val : $this->formatter->asTimestamp($val);
		$format = (null == $format) ? 'short' : $format;
		return $this->formatter->asTime($ts, $format);
	}

	/**
	* Return locally formatted date and time
	* Example: "Current time: <b>{{ now | datetime(short; short) }}</b>."
	* @param int|string $val If empty use current time
	* @param string $formatDate e.g. short|medium
	* @param string $formatTime e.g. short|medium
	* @param string $separator
	*/
	protected function dir_datetime($val, $formatDate = null, $formatTime = null, $separator = ' ')
	{
		if (!self::isDatetimeString($val)) {
			return $val;
		}
		$ts = is_numeric($val) ? (int) $val : $this->formatter->asTimestamp($val);
		$formatDate = (null == $formatDate) ? 'medium' : $formatDate;
		$date = $this->formatter->asDate($ts, $formatDate);
		$formatTime = (null == $formatTime) ? 'short' : $formatTime;
		$time = $this->formatter->asTime($ts, $formatTime);
		return $date.$separator.$time;
	}

	/**
	* Return Uppercase string e.g. "{{ user.name | upper }}"
	* @param string $val
	*/
	protected function dir_upper($val)
	{
		return mb_convert_case($val, MB_CASE_UPPER, 'utf-8');
	}

	/**
	* Return lowercased string e.g. "{{ user.address | lower }}"
	* @param string $val
	*/
	protected function dir_lower($val)
	{
		return mb_convert_case($val, MB_CASE_LOWER, 'utf-8');
	}

	/**
	* Return Titled String e.g. "{{ company.name | title }}"
	* @param string $val
	*/
	protected function dir_title($val)
	{
		return mb_convert_case($val, MB_CASE_TITLE, 'utf-8');
	}

	/**
	* Return number locally formatted to supplied decimals
	* Example: "{{ order.amount_due | round(3) }}"
	* @param int|float $val
	* @param int $decimals
	*/
	protected function dir_round($val, $decimals = 2)
	{
		// fix: convert to proper PHP format, e.g. "12 456,99" => 12456.99
		$val = (float) strtr(trim( (string) $val), [' ' => '', ',' => '.']);
		return $this->formatter->asDecimal($val, $decimals);
	}

	/**
	* Escape HTML input string e.g. "{{ user.name | escape }}"
	* @param string val Unsafe HTML string
	*/
	protected function dir_escape($val)
	{
		// convert:
		// < ... &lt;
		// > ... &gt;
		// & ... &amp;
		// " ... &quot;
		return htmlspecialchars($val);
	}

	/**
	* Alias shorthand to "escape" e.g. "{{ user.name | e }}"
	* @param string val Unsafe HTML string
	*/
	protected function dir_e($val)
	{
		return $this->dir_escape($val);
	}

	/**
	* Convert new lines into brackets e.g. "{{ order.notes | nl2br }}"
	* @param string val HTML/text string
	*/
	protected function dir_nl2br($val)
	{
		return nl2br(trim((string)$val));
	}

	/**
	* Truncate long strings "{{ user.name | truncate(10) }}"
	* @param string $val
	* @param int $length
	* @param string $suffix
	*/
	protected function dir_truncate($val, $length = 20, $suffix = '...')
	{
		return StringHelper::truncate(trim((string)$val), $length, $suffix);
	}

	/**
	* Trim strings e.g. "{{ user.name | trim }}"
	* @param string $val Trimmed value
	* @param string $chars Optional - trimmed characters
	*/
	protected function dir_trim($val, $chars = '')
	{
		$val = (string) $val;
		return '' === $chars ? trim($val) : trim($val, $chars);
	}

	/**
	* Return concatenated string e.g. "{{ concat("Hello ") | concat(user.name) }}"
	* Note: non-existing values and improperly quotes strings are ignored
	* @param string $val Placeholder value, gradually concatenated
	* @param string $txt Partial string to be concatenated
	* @param string $glue Delimiter e.g. defaults to single space " "
	*/
	protected function dir_concat($val, $txt, $glue = " ")
	{
		$txt = trim( (string) $txt);
		$val = (string) $val;
		$add = '';

		if (preg_match('/^["\']([^"\']+["\']$)/', $txt, $match)) {
			// quoted string e.g. "Hello" or 'Hello', quotes must be properly enclosed
			$add = trim($match[1], '"\'');
		}else{
			// interpolated value e.g. user.name, NULL if invalid
			$add = $this->getValue($txt, $this->resValues);
		}

		if ($add) {
			$glue = trim($glue, '\'"'); // quotes are not allowed within glue
			$val = $val ? "{$val}{$glue}{$add}" : $add;
		}

		return $val;
	}

	/**
	* Replace string within the string
	* @param string $val Passed in piped/chained value
	* @param string $what String to be replaced or REGEX e.g. "{{ name | replace( /(john)/i ; peter) }}"
	* @param string $replace New string
	*/
	protected function dir_replace($val, $what = '', $replace = '')
	{
		if($val && $what){
			if (preg_match('/^["\']([^"\']+["\']$)/', $what, $match)) {
				$what = trim($match[1], '"\'');
			} else {
				$what = trim((string)$what);
			}
			if (preg_match('/^["\']([^"\']+["\']$)/', $replace, $match)) {
				$replace = trim($match[1], '"\'');
			} else {
				$replace = trim((string)$replace);
			}
			if (preg_match('/^[\/@#](.+)[\/@#imsxADSUXJun]+$/', $what, $match)) {
				// REGEX replace - note: pipe | is not supported due to directive chaining, e.g. "/(word1|word2)/" will not work
				$val = preg_replace($match[0], $replace, $val);
			} else {
				// default string replace
				$val = str_replace($what, (string) $replace, $val);
			}
		}
		return $val;
	}
}

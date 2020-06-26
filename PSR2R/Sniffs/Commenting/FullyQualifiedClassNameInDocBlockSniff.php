<?php

namespace PSR2R\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PSR2R\Tools\AbstractSniff;

/**
 * Make sure all class names in doc blocks are FQCN (Fully Qualified Class Name).
 *
 * @author Mark Scherer
 * @license MIT
 */
class FullyQualifiedClassNameInDocBlockSniff extends AbstractSniff {

	/**
	 * @var string[]
	 */
	public static $whitelistedTypes = [
		'string', 'int', 'integer', 'float', 'bool', 'boolean', 'resource', 'null', 'void', 'callable',
		'array', 'iterable', 'mixed', 'object', 'false', 'true', 'self', 'static', '$this',
	];

	/**
	 * @inheritDoc
	 */
	public function register() {
		return [
			T_CLASS,
			T_INTERFACE,
			T_TRAIT,
			T_FUNCTION,
			T_VARIABLE,
			T_COMMENT,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function process(File $phpCsFile, $stackPointer) {
		$docBlockEndIndex = $this->findRelatedDocBlock($phpCsFile, $stackPointer);

		if (!$docBlockEndIndex) {
			return;
		}

		$tokens = $phpCsFile->getTokens();

		$docBlockStartIndex = $tokens[$docBlockEndIndex]['comment_opener'];

		for ($i = $docBlockStartIndex + 1; $i < $docBlockEndIndex; $i++) {
			if ($tokens[$i]['type'] !== 'T_DOC_COMMENT_TAG') {
				continue;
			}
			if (!in_array($tokens[$i]['content'], ['@return', '@param', '@throws', '@var', '@method', '@property'], true)) {
				continue;
			}

			$classNameIndex = $i + 2;

			if ($tokens[$classNameIndex]['type'] !== 'T_DOC_COMMENT_STRING') {
				continue;
			}

			$content = $tokens[$classNameIndex]['content'];

			$appendix = '';
			$spaceIndex = strpos($content, ' ');
			if ($spaceIndex) {
				$appendix = substr($content, $spaceIndex);
				$content = substr($content, 0, $spaceIndex);
			}

			if (!$content) {
				continue;
			}

			$types = $this->parseTypes($content);

			$this->fixClassNames($phpCsFile, $classNameIndex, $types, $appendix);
		}
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 * @param int $classNameIndex
	 * @param string[] $classNames
	 * @param string $appendix
	 *
	 * @return void
	 */
	protected function fixClassNames(File $phpCsFile, $classNameIndex, array $classNames, $appendix) {
		$classNameMap = $this->generateClassNameMap($phpCsFile, $classNameIndex, $classNames);
		if (!$classNameMap) {
			return;
		}

		$message = [];
		foreach ($classNameMap as $className => $useStatement) {
			$message[] = $className . ' => ' . $useStatement;
		}

		$fix = $phpCsFile->addFixableError(implode(', ', $message), $classNameIndex, 'FQCN');
		if ($fix) {
			$newContent = implode('|', $classNames);

			$phpCsFile->fixer->replaceToken($classNameIndex, $newContent . $appendix);
		}
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 * @param int $classNameIndex
	 * @param string[] $classNames
	 *
	 * @return string[]
	 */
	protected function generateClassNameMap(File $phpCsFile, $classNameIndex, array &$classNames) {
		$result = [];

		foreach ($classNames as $key => $className) {
			$arrayOfObject = 0;
			while (substr($className, -2) === '[]') {
				$arrayOfObject++;
				$className = substr($className, 0, -2);
			}

			if (preg_match('#^\((.+)\)#', $className, $matches)) {
				$subClassNames = explode('|', $matches[1]);
				$newClassName = '(' . $this->generateClassNameMapForUnionType($phpCsFile, $classNameIndex, $className, $subClassNames) . ')';
				if ($newClassName === $className) {
					continue;
				}

				$classNames[$key] = $newClassName . ($arrayOfObject ? str_repeat('[]', $arrayOfObject) : '');
				$result[$className . ($arrayOfObject ? str_repeat('[]', $arrayOfObject) : '')] = $classNames[$key];

				continue;
			}

			if (strpos($className, '\\') !== false) {
				continue;
			}

			if (in_array($className, static::$whitelistedTypes, true)) {
				continue;
			}
			$useStatement = $this->findUseStatementForClassName($phpCsFile, $className);
			if (!$useStatement) {
				$message = 'Invalid typehint `%s`';
				if (substr($className, 0, 1) === '$') {
					$message = 'The typehint seems to be missing for `%s`';
				}
				$phpCsFile->addError(sprintf($message, $className), $classNameIndex, 'ClassNameInvalid');

				continue;
			}
			$classNames[$key] = $useStatement . ($arrayOfObject ? str_repeat('[]', $arrayOfObject) : '');
			$result[$className . ($arrayOfObject ? str_repeat('[]', $arrayOfObject) : '')] = $classNames[$key];
		}

		return $result;
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 * @param string $className
	 *
	 * @return string|null
	 */
	protected function findUseStatementForClassName(File $phpCsFile, $className) {
		$useStatements = $this->parseUseStatements($phpCsFile);
		if (!isset($useStatements[$className])) {
			$useStatement = $this->findInSameNameSpace($phpCsFile, $className);
			if ($useStatement) {
				return $useStatement;
			}

			return null;
		}

		return $useStatements[$className];
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 * @param string $className
	 *
	 * @return string|null
	 */
	protected function findInSameNameSpace(File $phpCsFile, $className) {
		$currentNameSpace = $this->getNamespace($phpCsFile);
		if (!$currentNameSpace) {
			return null;
		}

		$file = $phpCsFile->getFilename();
		$dir = dirname($file) . DIRECTORY_SEPARATOR;
		if (!file_exists($dir . $className . '.php')) {
			return null;
		}

		return '\\' . $currentNameSpace . '\\' . $className;
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 *
	 * @return string
	 */
	protected function getNamespace(File $phpCsFile) {
		$tokens = $phpCsFile->getTokens();

		$namespaceStart = null;
		foreach ($tokens as $id => $token) {
			if ($token['code'] !== T_NAMESPACE) {
				continue;
			}

			$namespaceStart = $id + 1;

			break;
		}
		if (!$namespaceStart) {
			return '';
		}

		$namespaceEnd = $phpCsFile->findNext(
			[
				T_NS_SEPARATOR,
				T_STRING,
				T_WHITESPACE,
			],
			$namespaceStart,
			null,
			true
		);

		$namespace = trim($phpCsFile->getTokensAsString(($namespaceStart), ($namespaceEnd - $namespaceStart)));

		return $namespace;
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 *
	 * @return string[]
	 */
	protected function parseUseStatements(File $phpCsFile) {
		$useStatements = [];
		$tokens = $phpCsFile->getTokens();

		foreach ($tokens as $id => $token) {
			if ($token['type'] !== 'T_USE') {
				continue;
			}

			$endIndex = $phpCsFile->findEndOfStatement($id);
			$useStatement = '';
			for ($i = $id + 2; $i < $endIndex; $i++) {
				$useStatement .= $tokens[$i]['content'];
			}

			$useStatement = trim($useStatement);

			if (strpos($useStatement, ' as ') !== false) {
				[$useStatement, $className] = explode(' as ', $useStatement);
			} else {
				$className = $useStatement;
				if (strpos($useStatement, '\\') !== false) {
					$lastSeparator = strrpos($useStatement, '\\');
					$className = substr($useStatement, $lastSeparator + 1);
				}
			}

			$useStatement = '\\' . ltrim($useStatement, '\\');

			$useStatements[$className] = $useStatement;
		}

		return $useStatements;
	}

	/**
	 * @param string $content
	 *
	 * @return string[]
	 */
	protected function parseTypes($content) {
		preg_match_all('#\(.+\)#', $content, $matches);
		if (!$matches[0]) {
			return explode('|', $content);
		}
		$unionTypes = $matches[0];
		$map = [];
		foreach ($unionTypes as $i => $unionType) {
			$content = str_replace($unionType, '{{t' . $i . '}}', $content);
			$map['{{t' . $i . '}}'] = $unionType;
		}

		$types = explode('|', $content);
		foreach ($types as $k => $type) {
			$types[$k] = str_replace(array_keys($map), array_values($map), $type);
		}

		return $types;
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpCsFile
	 * @param int $classNameIndex
	 * @param string $className
	 * @param string[] $subClassNames
	 *
	 * @return string
	 */
	protected function generateClassNameMapForUnionType(File $phpCsFile, $classNameIndex, $className, array $subClassNames) {
		foreach ($subClassNames as $i => $subClassName) {
			if (strpos($subClassName, '\\') !== false) {
				continue;
			}

			if (in_array($subClassName, static::$whitelistedTypes, true)) {
				continue;
			}
			$useStatement = $this->findUseStatementForClassName($phpCsFile, $subClassName);
			if (!$useStatement) {
				$message = 'Invalid typehint `%s`';
				if (substr($subClassName, 0, 1) === '$') {
					$message = 'The typehint seems to be missing for `%s`';
				}
				$phpCsFile->addError(sprintf($message, $subClassName), $classNameIndex, 'ClassNameInvalid');

				continue;
			}
			$subClassNames[$i] = $useStatement;
		}

		return implode('|', $subClassNames);
	}

}

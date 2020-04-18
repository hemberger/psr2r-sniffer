<?php

namespace PSR2R\Sniffs\Commenting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use PSR2R\Tools\AbstractSniff;
use PSR2R\Tools\Traits\CommentingTrait;
use PSR2R\Tools\Traits\SignatureTrait;

/**
 * Methods always need doc blocks.
 * Constructor and destructor may not have one if they do not have arguments.
 */
class DocBlockSniff extends AbstractSniff {

	use CommentingTrait;
	use SignatureTrait;

	/**
	 * @inheritDoc
	 */
	public function register() {
		return [T_FUNCTION];
	}

	/**
	 * @inheritDoc
	 */
	public function process(File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();

		$nextIndex = $phpcsFile->findNext(Tokens::$emptyTokens, $stackPtr + 1, null, true);
		if ($tokens[$nextIndex]['content'] === '__construct' || $tokens[$nextIndex]['content'] === '__destruct') {
			$this->checkConstructorAndDestructor($phpcsFile, $nextIndex);

			return;
		}

		// Don't mess with closures
		$prevIndex = $phpcsFile->findPrevious(Tokens::$emptyTokens, $stackPtr - 1, null, true);
		if (!$this->isGivenKind(Tokens::$methodPrefixes, $tokens[$prevIndex])) {
			return;
		}

		$docBlockEndIndex = $this->findRelatedDocBlock($phpcsFile, $stackPtr);
		if ($docBlockEndIndex) {
			return;
		}

		// We only look for void methods right now
		$returnType = $this->detectReturnTypeVoid($phpcsFile, $stackPtr);
		if ($returnType === null) {
			$phpcsFile->addError('Method does not have a doc block: ' . $tokens[$nextIndex]['content'] . '()', $nextIndex, 'DocBlockMissing');

			return;
		}

		$fix = $phpcsFile->addFixableError('Method does not have a return void statement in doc block: ' . $tokens[$nextIndex]['content'], $nextIndex, 'ReturnVoidMissing');
		if (!$fix) {
			return;
		}

		$this->addDocBlock($phpcsFile, $stackPtr, $returnType);
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $index
	 * @param string $returnType
	 *
	 * @return void
	 */
	protected function addDocBlock(File $phpcsFile, $index, $returnType) {
		$tokens = $phpcsFile->getTokens();

		$firstTokenOfLine = $this->getFirstTokenOfLine($tokens, $index);

		$indentation = $this->getIndentationWhitespace($phpcsFile, $index);

		$phpcsFile->fixer->beginChangeset();
		$phpcsFile->fixer->addNewlineBefore($firstTokenOfLine);
		$phpcsFile->fixer->addContentBefore($firstTokenOfLine, $indentation . ' */');
		$phpcsFile->fixer->addNewlineBefore($firstTokenOfLine);
		$phpcsFile->fixer->addContentBefore($firstTokenOfLine, $indentation . ' * @return ' . $returnType);
		$phpcsFile->fixer->addNewlineBefore($firstTokenOfLine);
		$phpcsFile->fixer->addContentBefore($firstTokenOfLine, $indentation . '/**');
		$phpcsFile->fixer->endChangeset();
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $index
	 *
	 * @return void
	 */
	protected function checkConstructorAndDestructor(File $phpcsFile, $index) {
		$docBlockEndIndex = $this->findRelatedDocBlock($phpcsFile, $index);
		if ($docBlockEndIndex) {
			return;
		}

		$methodSignature = $this->getMethodSignature($phpcsFile, $index);
		$arguments = count($methodSignature);
		if (!$arguments) {
			return;
		}

		$phpcsFile->addError('Missing doc block for method', $index, 'ConstructDesctructMissingDocBlock');
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $docBlockStartIndex
	 * @param int $docBlockEndIndex
	 *
	 * @return int|null
	 */
	protected function findDocBlockReturn(File $phpcsFile, $docBlockStartIndex, $docBlockEndIndex) {
		$tokens = $phpcsFile->getTokens();

		for ($i = $docBlockStartIndex + 1; $i < $docBlockEndIndex; $i++) {
			if (!$this->isGivenKind(T_DOC_COMMENT_TAG, $tokens[$i])) {
				continue;
			}
			if ($tokens[$i]['content'] !== '@return') {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * For right now we only try to detect void.
	 *
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $index
	 *
	 * @return string|null
	 */
	protected function detectReturnTypeVoid(File $phpcsFile, $index) {
		$tokens = $phpcsFile->getTokens();

		$type = 'void';

		if (empty($tokens[$index]['scope_opener'])) {
			return null;
		}

		$methodStartIndex = $tokens[$index]['scope_opener'];
		$methodEndIndex = $tokens[$index]['scope_closer'];

		for ($i = $methodStartIndex + 1; $i < $methodEndIndex; ++$i) {
			if ($this->isGivenKind([T_FUNCTION], $tokens[$i])) {
				$endIndex = $tokens[$i]['scope_closer'];
				$i = $endIndex;

				continue;
			}

			if (!$this->isGivenKind([T_RETURN], $tokens[$i])) {
				continue;
			}

			$nextIndex = $phpcsFile->findNext(Tokens::$emptyTokens, $i + 1, null, true);
			if (!$this->isGivenKind(T_SEMICOLON, $tokens[$nextIndex])) {
				return null;
			}
		}

		return $type;
	}

}

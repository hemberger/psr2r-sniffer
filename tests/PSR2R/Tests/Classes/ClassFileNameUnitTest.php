<?php
/**
 * Created by PhpStorm.
 * User: ewb
 * Date: 6/17/17
 * Time: 2:56 PM
 */

namespace PSR2R\Tests\Classes;

use PSR2R\Base\AbstractBase;

class ClassFileNameUnitTest extends AbstractBase {
	protected function getErrorList() {
		return [11 => 1];
	}

	protected function getWarningList() {
		return [];
	}
}

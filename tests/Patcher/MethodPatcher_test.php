<?php

namespace Kenjis\MonkeyPatch\Patcher;

use TestCase;

/**
 * @group ci-phpunit-test
 * @group patcher
 */
class MethodPatcher_test extends TestCase
{
	public function setUp()
	{
		$this->obj = new MethodPatcher();
	}

	/**
	 * @dataProvider provide_source
	 */
	public function test_patch($source, $expected)
	{
		list($actual,) = $this->obj->patch($source);
		$this->assertEquals($expected, $actual);
	}

	public function provide_source()
	{
		return [
[<<<'EOL'
<?php
class Foo
{
	public function bar()
	{
		echo 'Bar';
	}
}
EOL
,
<<<'EOL'
<?php
class Foo
{
	public function bar()
	{ if (($__ret__ = \__PatchManager__::getReturn(__CLASS__, __FUNCTION__, func_get_args())) !== __GO_TO_ORIG__) return $__ret__;
		echo 'Bar';
	}
}
EOL
],

[<<<'EOL'
<?php
class Foo
{
	public function bar() {
		echo 'Bar';
	}
}
EOL
,
<<<'EOL'
<?php
class Foo
{
	public function bar() { if (($__ret__ = \__PatchManager__::getReturn(__CLASS__, __FUNCTION__, func_get_args())) !== __GO_TO_ORIG__) return $__ret__;
		echo 'Bar';
	}
}
EOL
],

[<<<'EOL'
<?php
class Foo
{
	public static function bar() {
		echo 'Bar';
	}
}
EOL
,
<<<'EOL'
<?php
class Foo
{
	public static function bar() { if (($__ret__ = \__PatchManager__::getReturn(__CLASS__, __FUNCTION__, func_get_args())) !== __GO_TO_ORIG__) return $__ret__;
		echo 'Bar';
	}
}
EOL
],

[<<<'EOL'
<?php
abstract class Foo
{
	protected abstract function bar();
	public function run()
	{
		$this->bar();
	}
}
EOL
,
<<<'EOL'
<?php
abstract class Foo
{
	protected abstract function bar();
	public function run()
	{ if (($__ret__ = \__PatchManager__::getReturn(__CLASS__, __FUNCTION__, func_get_args())) !== __GO_TO_ORIG__) return $__ret__;
		$this->bar();
	}
}
EOL
],

[<<<'EOL'
<?php
interface Foo
{
	public function bar();
}
EOL
,
<<<'EOL'
<?php
interface Foo
{
	public function bar();
}
EOL
],

[<<<'EOL'
<?php
class Foo
{
	public static function bar() : void
	{
		echo 'Bar';
	}
}
EOL
,
<<<'EOL'
<?php
class Foo
{
	public static function bar() : void
	{ if (($__ret__ = \__PatchManager__::getReturn(__CLASS__, __FUNCTION__, func_get_args())) !== __GO_TO_ORIG__) return;
		echo 'Bar';
	}
}
EOL
],
		];
	}
}

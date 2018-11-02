<?php declare(strict_types=1);

namespace Tests\Sturdy\Activity\TestUnit2;

use Sturdy\Activity\Meta\Action;

class Activity2
{
	/**
	 * @Action("start >action2")
	 */
	public function action1()
	{
	}

	/**
	 * @Action("end")
	 */
	public function action2()
	{
	}
}

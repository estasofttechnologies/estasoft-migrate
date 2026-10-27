<?php
/**
 * Base class of all long-running tasks.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * A task does its work in slices. Each call to step() runs until the budget is used up, saves
 * its position in $state, and returns. Call it again and it continues from the saved position.
 */
abstract class Task {

	/**
	 * Set up the state before the first slice. Throw TaskException when the job cannot start.
	 *
	 * @param array      $params Job parameters.
	 * @param array      $state  State, filled in here.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	abstract public function prepare( array $params, array &$state, JobContext $ctx );

	/**
	 * Work until the budget runs out or the task is done.
	 *
	 * @param array      $params Job parameters.
	 * @param array      $state  State, updated in place.
	 * @param Budget     $budget Limits for this slice.
	 * @param JobContext $ctx    Services.
	 * @return bool True when the task is complete.
	 */
	abstract public function step( array $params, array &$state, Budget $budget, JobContext $ctx );

	/**
	 * Overall progress, 0 to 100.
	 *
	 * @param array $params Job parameters.
	 * @param array $state  State.
	 * @return float
	 */
	abstract public function progress( array $params, array $state );

	/**
	 * Plain-language description of what is happening now.
	 *
	 * @param array $params Job parameters.
	 * @param array $state  State.
	 * @return string
	 */
	public function describe( array $params, array $state ) {
		return '';
	}

	/**
	 * Per-table progress for the Progress screen.
	 *
	 * @param array $params Job parameters.
	 * @param array $state  State.
	 * @return array<int,array{name:string, done:int, total:int, status:string}> status is done, running or waiting.
	 */
	public function tables( array $params, array $state ) {
		return array();
	}

	/**
	 * Steps of the job for the Progress screen.
	 *
	 * @param array $params Job parameters.
	 * @param array $state  State.
	 * @return array<int,array{id:string, label:string, status:string}> status is done, current or pending.
	 */
	public function steps( array $params, array $state ) {
		return array();
	}

	/**
	 * Can the job still be cancelled? False once its result is already in place and cancelling would leave it half done.
	 *
	 * @param array $params Job parameters.
	 * @param array $state  State.
	 * @return bool
	 */
	public function can_cancel( array $params, array $state ) {
		return true;
	}

	/**
	 * Remove leftovers after the job was cancelled or failed for good.
	 *
	 * @param array      $params Job parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function cleanup( array $params, array &$state, JobContext $ctx ) {
	}

	/**
	 * Hook called once after the last slice finished successfully.
	 *
	 * @param array      $params Job parameters.
	 * @param array      $state  State.
	 * @param JobContext $ctx    Services.
	 * @return void
	 */
	public function complete( array $params, array &$state, JobContext $ctx ) {
	}
}

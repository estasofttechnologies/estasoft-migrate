<?php
/**
 * Maps job types to task classes.
 *
 * @package Estasoft\Migrate
 */

namespace Estasoft\Migrate\Engine;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for JSON and textContent; PHP escapes them with esc_html() where it prints them.

/**
 * Factory for tasks. Extra types can be added with register().
 */
class TaskRegistry {

	/**
	 * Type => class name or factory callable.
	 *
	 * @var array<string,string|callable>
	 */
	private $types = array(
		'export'         => Tasks\ExportTask::class,
		'import'         => Tasks\ImportTask::class,
		'search_replace' => Tasks\SearchReplaceTask::class,
		'push'           => Tasks\PushTask::class,
		'pull'           => Tasks\PullTask::class,
		'verify'         => Tasks\VerifyTask::class,
	);

	/**
	 * Add or replace a type.
	 *
	 * @param string          $type    Type name.
	 * @param string|callable $factory Class name or callable returning a Task.
	 * @return void
	 */
	public function register( $type, $factory ) {
		$this->types[ $type ] = $factory;
	}

	/**
	 * Build a task.
	 *
	 * @param string $type Type name.
	 * @return Task
	 * @throws TaskException For unknown types.
	 */
	public function make( $type ) {
		if ( ! isset( $this->types[ $type ] ) ) {
			throw new TaskException( 'Unknown job type: ' . $type );
		}
		$factory = $this->types[ $type ];
		$task    = is_callable( $factory ) ? call_user_func( $factory ) : new $factory();
		return $task;
	}

	/**
	 * Known types.
	 *
	 * @return string[]
	 */
	public function types() {
		return array_keys( $this->types );
	}
}

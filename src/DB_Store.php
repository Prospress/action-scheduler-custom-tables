<?php

namespace Action_Scheduler\Custom_Tables;

use ActionScheduler_Action;
use ActionScheduler_ActionClaim;
use ActionScheduler_CanceledAction;
use ActionScheduler_FinishedAction;
use ActionScheduler_NullAction;
use ActionScheduler_NullSchedule;
use ActionScheduler_Store;

class DB_Store extends ActionScheduler_Store {


	/**
	 * @codeCoverageIgnore
	 */
	public function init() {
		$table_maker = new DB_Store_Table_Maker();
		$table_maker->register_tables();
	}


	public function save_action( ActionScheduler_Action $action, \DateTime $date = null ) {
		try {
			/** @var \wpdb $wpdb */
			global $wpdb;
			$data = [
				'hook'                 => $action->get_hook(),
				'status'               => ( $action->is_finished() ? self::STATUS_COMPLETE : self::STATUS_PENDING ),
				'scheduled_date_gmt'   => $this->get_scheduled_date_string( $action, $date ),
				'scheduled_date_local' => $this->get_scheduled_date_string_local( $action, $date ),
				'args'                 => json_encode( $action->get_args() ),
				'schedule'             => serialize( $action->get_schedule() ),
				'group_id'             => $this->get_group_id( $action->get_group() ),
			];
			$wpdb->insert( $wpdb->actionscheduler_actions, $data );
			$action_id = $wpdb->insert_id;

			do_action( 'action_scheduler_stored_action', $action_id );

			return $action_id;
		} catch ( \Exception $e ) {
			throw new \RuntimeException( sprintf( __( 'Error saving action: %s', 'action-scheduler' ), $e->getMessage() ), 0 );
		}
	}

	protected function get_group_id( $slug, $create_if_not_exists = true ) {
		if ( empty( $slug ) ) {
			return 0;
		}
		/** @var \wpdb $wpdb */
		global $wpdb;
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$wpdb->actionscheduler_groups} WHERE slug=%s", $slug ) );
		if ( empty( $group_id ) && $create_if_not_exists ) {
			$group_id = $this->create_group( $slug );
		}

		return $group_id;
	}

	protected function create_group( $slug ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$wpdb->insert( $wpdb->actionscheduler_groups, [ 'slug' => $slug ] );

		return (int) $wpdb->insert_id;
	}

	public function fetch_action( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$data = $wpdb->get_row( $wpdb->prepare(
			"SELECT a.*, g.slug AS `group` FROM {$wpdb->actionscheduler_actions} a LEFT JOIN {$wpdb->actionscheduler_groups} g ON a.group_id=g.group_id WHERE a.action_id=%d",
			$action_id
		) );

		if ( empty( $data ) ) {
			return $this->get_null_action();
		}

		return $this->make_action_from_db_record( $data );
	}

	protected function get_null_action() {
		return new ActionScheduler_NullAction();
	}

	protected function make_action_from_db_record( $data ) {

		$hook     = $data->hook;
		$args     = json_decode( $data->args, true );
		$schedule = unserialize( $data->schedule );
		if ( empty( $schedule ) ) {
			$schedule = new ActionScheduler_NullSchedule();
		}
		$group = $data->group ? $data->group : '';
		if ( $data->status == self::STATUS_PENDING ) {
			$action = new ActionScheduler_Action( $hook, $args, $schedule, $group );
		} elseif ( $data->status == self::STATUS_CANCELED ) {
			$action = new ActionScheduler_CanceledAction( $hook, $args, $schedule, $group );
		} else {
			$action = new ActionScheduler_FinishedAction( $hook, $args, $schedule, $group );
		}

		return $action;
	}

	/**
	 * @param string $hook
	 * @param array  $params
	 *
	 * @return string ID of the next action matching the criteria or NULL if not found
	 */
	public function find_action( $hook, $params = [] ) {
		$params = wp_parse_args( $params, [
			'args'   => null,
			'status' => self::STATUS_PENDING,
			'group'  => '',
		] );

		/** @var wpdb $wpdb */
		global $wpdb;
		$query = "SELECT a.action_id FROM {$wpdb->actionscheduler_actions} a";
		$args  = [];
		if ( ! empty( $params[ 'group' ] ) ) {
			$query  .= " INNER JOIN {$wpdb->actionscheduler_groups} g ON g.group_id=a.group_id AND g.slug=%s";
			$args[] = $params[ 'group' ];
		}
		$query  .= " WHERE a.hook=%s";
		$args[] = $hook;
		if ( ! is_null( $params[ 'args' ] ) ) {
			$query  .= " AND a.args=%s";
			$args[] = json_encode( $params[ 'args' ] );
		}

		$order = 'ASC';
		if ( ! empty( $params[ 'status' ] ) ) {
			$query  .= " AND a.status=%s";
			$args[] = $params[ 'status' ];

			if ( self::STATUS_PENDING == $params[ 'status' ] ) {
				$order = 'ASC'; // Find the next action that matches
			} else {
				$order = 'DESC'; // Find the most recent action that matches
			}
		}

		$query .= " ORDER BY scheduled_date_gmt $order LIMIT 1";

		$query = $wpdb->prepare( $query, $args );

		$id = $wpdb->get_var( $query );

		return $id;
	}

	/**
	 * Returns the SQL statement to query (or count) actions.
	 *
	 * @param array $query Filtering options
	 * @param string $select_or_count  Whether the SQL should select and return the IDs or just the row count
	 *
	 * @return string SQL statement. The returned SQL is already properly escaped.
	 */
	protected function get_query_actions_sql( array $query, $select_or_count = 'select' ) {

		if ( ! in_array( $select_or_count, array( 'select', 'count' ) ) ) {
			throw new \InvalidArgumentException( __( 'Invalid valud for select or count parameter. Cannot query actions.', 'action-scheduler' ) );
		}

		$query = wp_parse_args( $query, [
			'hook'             => '',
			'args'             => null,
			'date'             => null,
			'date_compare'     => '<=',
			'modified'         => null,
			'modified_compare' => '<=',
			'group'            => '',
			'status'           => '',
			'claimed'          => null,
			'per_page'         => 5,
			'offset'           => 0,
			'orderby'          => 'date',
			'order'            => 'ASC',
		] );

		/** @var \wpdb $wpdb */
		global $wpdb;
		$sql  = ( 'count' === $select_or_count ) ? 'SELECT count(a.action_id)' : 'SELECT a.action_id ';
		$sql .= "FROM {$wpdb->actionscheduler_actions} a";
		$sql_params = [];

		$sql .= " LEFT JOIN {$wpdb->actionscheduler_groups} g ON g.group_id=a.group_id";
		$sql .= " WHERE 1=1";

		if ( ! empty( $query[ 'group' ] ) ) {
			$sql          .= " AND g.slug=%s";
			$sql_params[] = $query[ 'group' ];
		}

		if ( $query[ 'hook' ] ) {
			$sql          .= " AND a.hook=%s";
			$sql_params[] = $query[ 'hook' ];
		}
		if ( ! is_null( $query[ 'args' ] ) ) {
			$sql          .= " AND a.args=%s";
			$sql_params[] = json_encode( $query[ 'args' ] );
		}

		if ( $query[ 'status' ] ) {
			$sql          .= " AND a.status=%s";
			$sql_params[] = $query[ 'status' ];
		}

		if ( $query[ 'date' ] instanceof \DateTime ) {
			$date = clone $query[ 'date' ];
			$date->setTimezone( new \DateTimeZone( 'UTC' ) );
			$date_string  = $date->format( 'Y-m-d H:i:s' );
			$comparator   = $this->validate_sql_comparator( $query[ 'date_compare' ] );
			$sql          .= " AND a.scheduled_date_gmt $comparator %s";
			$sql_params[] = $date_string;
		}

		if ( $query[ 'modified' ] instanceof \DateTime ) {
			$modified = clone $query[ 'modified' ];
			$modified->setTimezone( new \DateTimeZone( 'UTC' ) );
			$date_string  = $modified->format( 'Y-m-d H:i:s' );
			$comparator   = $this->validate_sql_comparator( $query[ 'modified_compare' ] );
			$sql          .= " AND a.last_attempt_gmt $comparator %s";
			$sql_params[] = $date_string;
		}

		if ( $query[ 'claimed' ] === true ) {
			$sql .= " AND a.claim_id != 0";
		} elseif ( $query[ 'claimed' ] === false ) {
			$sql .= " AND a.claim_id = 0";
		} elseif ( ! is_null( $query[ 'claimed' ] ) ) {
			$sql          .= " AND a.claim_id = %d";
			$sql_params[] = $query[ 'claimed' ];
		}

		if ( 'select' === $select_or_count ) {
			switch ( $query['orderby'] ) {
				case 'hook':
					$orderby = 'a.hook';
					break;
				case 'group':
					$orderby = 'g.slug';
					break;
				case 'modified':
					$orderby = 'a.last_attempt_gmt';
					break;
				case 'date':
				default:
					$orderby = 'a.scheduled_date_gmt';
					break;
			}
			if ( strtoupper( $query[ 'order' ] ) == 'ASC' ) {
				$order = 'ASC';
			} else {
				$order = 'DESC';
			}
			$sql .= " ORDER BY $orderby $order";
			if ( $query[ 'per_page' ] > 0 ) {
				$sql          .= " LIMIT %d, %d";
				$sql_params[] = $query[ 'offset' ];
				$sql_params[] = $query[ 'per_page' ];
			}
		}

		if ( ! empty( $sql_params ) ) {
			$sql = $wpdb->prepare( $sql, $sql_params );
		}

		return $sql;
	}

	/**
	 * @param array $query
	 * @param string $query_type Whether to select or count the results. Default, select.
	 * @return null|string|array The IDs of actions matching the query
	 */
	public function query_actions( $query = [], $query_type = 'select' ) {
		/** @var wpdb $wpdb */
		global $wpdb;

		$sql = $this->get_query_actions_sql( $query, $query_type );

		return ( 'count' === $query_type ) ? $wpdb->get_var( $sql ) : $wpdb->get_col( $sql );
	}

	/**
	 * Get a count of all actions in the store, grouped by status
	 *
	 * @return array Set of 'status' => int $count pairs for statuses with 1 or more actions of that status.
	 */
	public function action_counts() {
		global $wpdb;

		$sql  = "SELECT a.status, count(a.status) as 'count'";
		$sql .= " FROM {$wpdb->actionscheduler_actions} a";
		$sql .= " GROUP BY a.status";

		$actions_count_by_status = array();
		$action_stati_and_labels = $this->get_status_labels();

		foreach ( $wpdb->get_results( $sql ) as $action_data ) {
			// Ignore any actions with invalid status
			if ( array_key_exists( $action_data->status, $action_stati_and_labels ) ) {
				$actions_count_by_status[ $action_data->status ] = $action_data->count;
			}
		}

		return $actions_count_by_status;
	}

	/**
	 * @param string $action_id
	 *
	 * @throws \InvalidArgumentException
	 * @return void
	 */
	public function cancel_action( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->actionscheduler_actions,
			[ 'status' => self::STATUS_CANCELED ],
			[ 'action_id' => $action_id ],
			[ '%s' ],
			[ '%d' ]
		);
		if ( empty( $updated ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Unidentified action %s', 'action-scheduler' ), $action_id ) );
		}
		do_action( 'action_scheduler_canceled_action', $action_id );
	}


	public function delete_action( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$deleted = $wpdb->delete( $wpdb->actionscheduler_actions, [ 'action_id' => $action_id ], [ '%d' ] );
		if ( empty( $deleted ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Unidentified action %s', 'action-scheduler' ), $action_id ) );
		}
		do_action( 'action_scheduler_deleted_action', $action_id );
	}

	/**
	 * @param string $action_id
	 *
	 * @throws \InvalidArgumentException
	 * @return \DateTime The local date the action is scheduled to run, or the date that it ran.
	 */
	public function get_date( $action_id ) {
		$date = $this->get_date_gmt( $action_id );

		return $date->setTimezone( $this->get_local_timezone() );
	}

	/**
	 * @param string $action_id
	 *
	 * @throws \InvalidArgumentException
	 * @return \DateTime The GMT date the action is scheduled to run, or the date that it ran.
	 */
	protected function get_date_gmt( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->actionscheduler_actions} WHERE action_id=%d", $action_id ) );
		if ( empty( $record ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Unidentified action %s', 'action-scheduler' ), $action_id ) );
		}
		if ( $record->status == self::STATUS_PENDING ) {
			return as_get_datetime_object( $record->scheduled_date_gmt );
		} else {
			return as_get_datetime_object( $record->last_attempt_gmt );
		}
	}


	/**
	 * @param int       $max_actions
	 * @param \DateTime $before_date Jobs must be schedule before this date. Defaults to now.
	 *
	 * @return ActionScheduler_ActionClaim
	 */
	public function stake_claim( $max_actions = 10, \DateTime $before_date = null ) {
		$claim_id = $this->generate_claim_id();
		$this->claim_actions( $claim_id, $max_actions, $before_date );
		$action_ids = $this->find_actions_by_claim_id( $claim_id );

		return new ActionScheduler_ActionClaim( $claim_id, $action_ids );
	}


	protected function generate_claim_id() {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$now = as_get_datetime_object();
		$wpdb->insert( $wpdb->actionscheduler_claims, [ 'date_created_gmt' => $now->format( 'Y-m-d H:i:s' ) ] );

		return $wpdb->insert_id;
	}

	/**
	 * @param string    $claim_id
	 * @param int       $limit
	 * @param \DateTime $before_date Should use UTC timezone.
	 *
	 * @return int The number of actions that were claimed
	 * @throws \RuntimeException
	 */
	protected function claim_actions( $claim_id, $limit, \DateTime $before_date = null ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$now  = as_get_datetime_object();
		$date = is_null( $before_date ) ? $now : clone $before_date;

		// can't use $wpdb->update() because of the <= condition
		$sql = "UPDATE {$wpdb->actionscheduler_actions} SET claim_id=%d, last_attempt_gmt=%s, last_attempt_local=%s WHERE claim_id = 0 AND scheduled_date_gmt <= %s AND status=%s ORDER BY attempts ASC, scheduled_date_gmt ASC LIMIT %d";

		$sql = $wpdb->prepare( $sql, [
			$claim_id,
			$now->format( 'Y-m-d H:i:s' ),
			current_time( 'mysql' ),
			$date->format( 'Y-m-d H:i:s' ),
			self::STATUS_PENDING,
			$limit,
		] );

		$rows_affected = $wpdb->query( $sql );
		if ( $rows_affected === false ) {
			throw new \RuntimeException( __( 'Unable to claim actions. Database error.', 'action-scheduler' ) );
		}

		return (int) $rows_affected;
	}

	/**
	 * @return int
	 */
	public function get_claim_count() {
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT claim_id) FROM {$wpdb->actionscheduler_actions} WHERE claim_id != 0 AND status IN ( %s, %s)";
		$sql = $wpdb->prepare( $sql, [ self::STATUS_PENDING, self::STATUS_RUNNING ] );

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return an action's claim ID, as stored in the claim_id column
	 *
	 * @param string $action_id
	 * @return mixed
	 */
	public function get_claim_id( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$sql = "SELECT claim_id FROM {$wpdb->actionscheduler_actions} WHERE action_id=%d";
		$sql = $wpdb->prepare( $sql, $action_id );

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param string $claim_id
	 *
	 * @return int[]
	 */
	public function find_actions_by_claim_id( $claim_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$sql = "SELECT action_id FROM {$wpdb->actionscheduler_actions} WHERE claim_id=%d";
		$sql = $wpdb->prepare( $sql, $claim_id );

		$action_ids = $wpdb->get_col( $sql );

		return array_map( 'intval', $action_ids );
	}

	public function release_claim( ActionScheduler_ActionClaim $claim ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$wpdb->update( $wpdb->actionscheduler_actions, [ 'claim_id' => 0 ], [ 'claim_id' => $claim->get_id() ], [ '%d' ], [ '%d' ] );
		$wpdb->delete( $wpdb->actionscheduler_claims, [ 'claim_id' => $claim->get_id() ], [ '%d' ] );
	}

	/**
	 * @param string $action_id
	 *
	 * @return void
	 */
	public function unclaim_action( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$wpdb->update(
			$wpdb->actionscheduler_actions,
			[ 'claim_id' => 0 ],
			[ 'action_id' => $action_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public function mark_failure( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->actionscheduler_actions,
			[ 'status' => self::STATUS_FAILED ],
			[ 'action_id' => $action_id ],
			[ '%s' ],
			[ '%d' ]
		);
		if ( empty( $updated ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Unidentified action %s', 'action-scheduler' ), $action_id ) );
		}
	}

	/**
	 * @param string $action_id
	 *
	 * @return void
	 */
	public function log_execution( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$sql = "UPDATE {$wpdb->actionscheduler_actions} SET attempts = attempts+1, status=%s, last_attempt_gmt = %s, last_attempt_local = %s WHERE action_id = %d";
		$sql = $wpdb->prepare( $sql, self::STATUS_RUNNING, current_time( 'mysql', true ), current_time( 'mysql' ), $action_id );
		$wpdb->query( $sql );
	}


	public function mark_complete( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->actionscheduler_actions,
			[
				'status'             => self::STATUS_COMPLETE,
				'last_attempt_gmt'   => current_time( 'mysql', true ),
				'last_attempt_local' => current_time( 'mysql' ),
			],
			[ 'action_id' => $action_id ],
			[ '%s' ],
			[ '%d' ]
		);
		if ( empty( $updated ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'Unidentified action %s', 'action-scheduler' ), $action_id ) );
		}
	}

	public function get_status( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$sql    = "SELECT status FROM {$wpdb->actionscheduler_actions} WHERE action_id=%d";
		$sql    = $wpdb->prepare( $sql, $action_id );
		$status = $wpdb->get_var( $sql );

		if ( $status === null ) {
			throw new \InvalidArgumentException( __( 'Invalid action ID. No status found.', 'action-scheduler' ) );
		} elseif ( empty( $status ) ) {
			throw new \RuntimeException( __( 'Unknown status found for action.', 'action-scheduler' ) );
		} else {
			return $status;
		}
	}


}

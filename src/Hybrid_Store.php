<?php


namespace Action_Scheduler\Custom_Tables;


use Action_Scheduler\Custom_Tables\Migration\Migration_Config;
use Action_Scheduler\Custom_Tables\Migration\Migration_Runner;
use ActionScheduler_Action;
use ActionScheduler_ActionClaim;
use ActionScheduler_Store as Store;
use DateTime;

/**
 * Class Hybrid_Store
 *
 * A wrapper around multiple stores that fetches data from both
 */
class Hybrid_Store extends Store {
	const DEMARKATION_OPTION = 'action_scheduler_hybrid_store_demarkation';

	private $primary_store;
	private $secondary_store;
	private $migration_runner;

	/**
	 * @var int The dividing line between IDs of actions created
	 *          by the primary and secondary stores.
	 *
	 * Methods that accept an action ID will compare the ID against
	 * this to determine which store will contain that ID. In almost
	 * all cases, the ID should come from the primary store, but if
	 * client code is bypassing the API functions and fetching IDs
	 * from elsewhere, then there is a chance that an unmigrated ID
	 * might be requested.
	 */
	private $demarkation_id = 0;

	public function __construct( Migration_Config $config = null ) {
		$this->demarkation_id = (int) get_option( self::DEMARKATION_OPTION, 0 );
		if ( empty( $config ) ) {
			$config = Plugin::instance()->get_migration_config_object();
		}
		$this->primary_store    = $config->get_destination_store();
		$this->secondary_store  = $config->get_source_store();
		$this->migration_runner = new Migration_Runner( $config );
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function init() {
		add_action( 'action_scheduler/custom_tables/created_table', [ $this, 'set_autoincrement' ], 10, 2 );
		$this->primary_store->init();
		$this->secondary_store->init();
		remove_action( 'action_scheduler/custom_tables/created_table', [ $this, 'set_autoincrement' ], 10 );
	}

	/**
	 * When the actions table is created, set its autoincrement
	 * value to be one higher than the posts table to ensure that
	 * there are no ID collisions.
	 *
	 * @param string $table_name
	 * @param string $table_suffix
	 *
	 * @return void
	 * @codeCoverageIgnore
	 */
	public function set_autoincrement( $table_name, $table_suffix ) {
		if ( DB_Store_Table_Maker::ACTIONS_TABLE === $table_suffix ) {
			if ( empty( $this->demarkation_id ) ) {
				$this->demarkation_id = $this->set_demarkation_id();
			}
			/** @var \wpdb $wpdb */
			global $wpdb;
			$wpdb->insert(
				$wpdb->{DB_Store_Table_Maker::ACTIONS_TABLE},
				[
					'action_id' => $this->demarkation_id,
					'hook'      => '',
					'status'    => '',
				]
			);
			$wpdb->delete(
				$wpdb->{DB_Store_Table_Maker::ACTIONS_TABLE},
				[ 'action_id' => $this->demarkation_id ]
			);
		}
	}

	/**
	 * @param int $id The ID to set as the demarkation point between the two stores
	 *                Leave null to use the next ID from the WP posts table.
	 *
	 * @return int The new ID.
	 *
	 * @codeCoverageIgnore
	 */
	private function set_demarkation_id( $id = null ) {
		if ( empty( $id ) ) {
			/** @var \wpdb $wpdb */
			global $wpdb;
			$id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->posts" );
			$id ++;
		}
		update_option( self::DEMARKATION_OPTION, $id );

		return $id;
	}

	/**
	 * Find the first matching action from the secondary store.
	 * If it exists, migrate it to the primary store immediately.
	 * After it migrates, the secondary store will logically contain
	 * the next matching action, so return the result thence.
	 *
	 * @param string $hook
	 * @param array  $params
	 *
	 * @return string
	 */
	public function find_action( $hook, $params = [] ) {
		$found_unmigrated_action = $this->secondary_store->find_action( $hook, $params );
		if ( ! empty( $found_unmigrated_action ) ) {
			$this->migrate( [ $found_unmigrated_action ] );
		}

		return $this->primary_store->find_action( $hook, $params );
	}

	/**
	 * Find actions matching the query in the secondary source first.
	 * If any are found, migrate them immediately. Then the secondary
	 * store will contain the canonical results.
	 *
	 * @param array $query
	 *
	 * @return int[]
	 */
	public function query_actions( $query = [] ) {
		$found_unmigrated_actions = $this->secondary_store->query_actions( $query );
		if ( ! empty( $found_unmigrated_actions ) ) {
			$this->migrate( $found_unmigrated_actions );
		}

		return $this->primary_store->query_actions( $query );
	}

	/**
	 * If any actions would have been claimed by the secondary store,
	 * migrate them immediately, then ask the primary store for the
	 * canonical claim.
	 *
	 * @param int           $max_actions
	 * @param DateTime|null $before_date
	 *
	 * @return ActionScheduler_ActionClaim
	 */
	public function stake_claim( $max_actions = 10, DateTime $before_date = null ) {
		$claim = $this->secondary_store->stake_claim( $max_actions, $before_date );

		$claimed_actions = $claim->get_actions();
		if ( ! empty( $claimed_actions ) ) {
			$this->migrate( $claimed_actions );
		}

		$this->secondary_store->release_claim( $claim );

		return $this->primary_store->stake_claim( $max_actions, $before_date );
	}

	private function migrate( $action_ids ) {
		$this->migration_runner->migrate_actions( $action_ids );
	}

	public function save_action( ActionScheduler_Action $action, DateTime $date = null ) {
		return $this->primary_store->save_action( $action, $date );
	}

	public function fetch_action( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			return $this->secondary_store->fetch_action( $action_id );
		} else {
			return $this->primary_store->fetch_action( $action_id );
		}
	}

	public function cancel_action( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			$this->secondary_store->cancel_action( $action_id );
		} else {
			$this->primary_store->cancel_action( $action_id );
		}
	}

	public function delete_action( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			$this->secondary_store->delete_action( $action_id );
		} else {
			$this->primary_store->delete_action( $action_id );
		}
	}

	public function get_date( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			return $this->secondary_store->get_date( $action_id );
		} else {
			return $this->primary_store->get_date( $action_id );
		}
	}

	public function mark_failure( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			$this->secondary_store->mark_failure( $action_id );
		} else {
			$this->primary_store->mark_failure( $action_id );
		}
	}

	public function log_execution( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			$this->secondary_store->log_execution( $action_id );
		} else {
			$this->primary_store->log_execution( $action_id );
		}
	}

	public function mark_complete( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			$this->secondary_store->mark_complete( $action_id );
		} else {
			$this->primary_store->mark_complete( $action_id );
		}
	}

	public function get_status( $action_id ) {
		if ( $action_id < $this->demarkation_id ) {
			return $this->secondary_store->get_status( $action_id );
		} else {
			return $this->primary_store->get_status( $action_id );
		}
	}

	/**
	 * Determine whether the action is in the primary store.
	 *
	 * @todo Use this method for other methods in this class.
	 *
	 * @param mixed $action_id
	 *
	 * @return bool
	 */
	protected function action_in_primary_store( $action_id ) {
		return $action_id >= $this->demarkation_id;
	}

	/* * * * * * * * * * * * * * * * * * * * * * * * * * *
	 * All claim-related functions should operate solely
	 * on the primary store.
	 * * * * * * * * * * * * * * * * * * * * * * * * * * */

	public function get_claim_count() {
		return $this->primary_store->get_claim_count();
	}

	public function release_claim( ActionScheduler_ActionClaim $claim ) {
		$this->primary_store->release_claim( $claim );
	}

	public function unclaim_action( $action_id ) {
		$this->primary_store->unclaim_action( $action_id );
	}

	public function find_actions_by_claim_id( $claim_id ) {
		return $this->primary_store->find_actions_by_claim_id( $claim_id );
	}

	/**
	 * Update an existing action by ID.
	 *
	 * This will check whether the action has been migrated, and migrate it if necessary.
	 *
	 * @param string $action_id The action ID to update.
	 * @param array  $fields    The array of field data to update.
	 *
	 * @return mixed
	 */
	public function update_action( $action_id, array $fields ) {
		if ( ! $this->action_in_primary_store( $action_id ) ) {
			$this->migrate( [ $action_id ] );
		}

		return $this->primary_store->update_action( $action_id, $fields );
	}

	/**
	 * Get the last time the action was attempted.
	 *
	 * The time should be given in GMT.
	 *
	 * @param string $action_id
	 *
	 * @return DateTime|null
	 */
	public function get_last_attempt( $action_id ) {
		return $this->action_in_primary_store( $action_id )
			? $this->primary_store->get_last_attempt( $action_id )
			: $this->secondary_store->get_last_attempt( $action_id );
	}

	/**
	 * Get the last time the action was attempted.
	 *
	 * The time should be given in the local time of the site.
	 *
	 * @param string $action_id
	 *
	 * @return DateTime|null
	 */
	public function get_last_attempt_local( $action_id ) {
		return $this->action_in_primary_store( $action_id )
			? $this->primary_store->get_last_attempt_local( $action_id )
			: $this->secondary_store->get_last_attempt_local( $action_id );
	}
}

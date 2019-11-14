<?php
	require(dirname(__FILE__) . '/incCommon.php');
	/*
	 * The purpose of this page is provide a progressive means of updating
	 * calculated fields in all tables.
	 * 
	 * This could be a bit heavy on server resources, specially for large
	 * databases. To prevent abuse of server, updates would be performed
	 * using lazy ajax calls
	 */

	new updateCalculatedFields($_REQUEST);

	class updateCalculatedFields {
		private $tables, 
			$table_records, /* num of records per table */
			$curr_dir, 
			$t, /* translation */
			$pks, /* a batch of PKs organized as $pks['tablename'] = ['pk1', 'pk2', ...] */
			$max_batch_size = 1000
			;

		public function __construct($req) {
			global $Translation;
			$this->tables = getTableList(true);
			$this->curr_dir = dirname(__FILE__);
			$this->t = $Translation;

			$this->update_table_records();

			$this->process_request(
				isset($req['request']) ? $req['request'] : null, 
				isset($req['tn']) ? $req['tn'] : null, 
				isset($req['start']) ? $req['start'] : null
			);
		}

		private function update_table_records() {
			if(!count($this->tables)) return; // no tables to get count of records of
			if(count($this->table_records)) return; // table records count already retrieved

			foreach($this->tables as $tn => $tc)
				$this->table_records[$tn] = intval(sqlValue("SELECT COUNT(1) FROM `{$tn}`"));
		}

		private function js_functions() {
			@header('Content-Type: text/javascript; charset=' . datalist_db_encoding);
			echo implode("\n", array(
				$this->js_populateTablesList(),
				$this->js_batch_classes(),
				$this->js_handleTableSelection(),
				$this->js_handleStart(),
				$this->js_handleStop(),
				$this->js_init(),
			));
		}

		private function populate_pks($tn, $start = 0, $length = null) {
			if(!in_array($tn, $this->tables)) return false;
			$pkf = getPKFieldName($tn);
			if($pkf === false) return false;

			$start = abs(intval($start));
			if($length === null) $length = $this->max_batch_size;
			$length = min(abs(intval($length)), $this->max_batch_size);

			if(!isset($this->pks[$tn])) $this->pks[$tn] = array();
			
			$eo = array('silentErrors' => true);
			$res = sql("SELECT `{$pkf}` FROM `{$tn}` ORDER BY `{$pkf}` LIMIT {$start}, {$length}", $eo);
			while($row = db_fetch_assoc($res)){
				$this->pks[$tn][$start++] = $row[$pkf];
			}
		}

		private function js_populateTablesList() {
			ob_start(); ?>
			var populateTablesList = function() {
				// if table already created, no action
				if($j('#tables-list table').length) return;

				$j(
					'<table class="table table-bordered table-striped">' +
						'<thead>' +
							'<tr>' +
								'<th style="width: 2.5rem;"><input type="checkbox" class="toggle-all-tables"></th>' +
								'<th class="text-center"></th>' +
								'<th class="text-center">' + $t['table'] + '</th>' +
								'<th class="text-center" style="text-transform: capitalize;">' + 
									$t['records'] + 
								'</th>' +
								'<th class="text-center" style="width: 50%;">' + $t['Update progress'] + '</th>' +
								'<th class="text-center" style="width: 25%;">' + $t['description'] + '</th>' +
							'</tr>' +
						'</thead>' +
						'<tbody></tbody>' +
					'</table>'
				).appendTo('#tables-list');

				// add tables
				for(t in tables) {
					$j(
						'<tr data-table="' + t + '">' +
							'<td><input type="checkbox" class="toggle-table" data-table="' + t + '"></td>' +
							'<td></td>' +
							'<td class="text-right">' + tables[t] + '</td>' +
							'<td class="text-right">' + tableRecordCount[t] + '</td>' +
							'<td style="height: 4em;">' + 
							'</td>' +
							'<td><div class="description">' +
								'' +
							'</div></td>' +
						'</tr>'
					).appendTo('#tables-list tbody');
				}
			}
			<?php
			return ob_get_clean();
		}

		private function js_batch_classes() {
			ob_start(); ?>

			var launchAjaxUpdateCalculatedRecord = function(ajaxConf) {
// TODO
			};

			var updateStatus = {
				PENDING: 0, // default intial status
				INPROGRESS: 1,
				DONE: 2,
				ERROR: -1
			};

			var BatchTable = function(tn) {
				return {
					tn: tn,

					// until proven false!
					hasCalculatedFields: true,
					first: 1,

					// until an ajax request says otherwise!
					last: 1000,

					// when batch starts, this would be set to 1 then increment
					current: null,

					// when batch is running, this is true.
					// when paused/stopped, this is false.
					// to know if batch is paused or stopped, also check .current
					// (should be null if stopped, or a number if paused)
					running: false,

					// an array of BatchRecord objects. usage:
					// bt.records.push(BatchRecord('pkvalue'));
					records: [],

					_updateCurrentRecord: function() {
						if(!this.running) return;
						
						if(this.current >= this.last) {
							this.running = false;
							return;
						}

						var currentRecord = this.records[this.current - 1];

						if(currentRecord.updateStatus === updateStatus.INPROGRESS) {
							// TODO
							// ?????????????????
							// i'm not sure if this cause would happen
							// but if it does, I guess we should stop/return?
							// or move to next, like DONE?
							// ?????????????????
							// for now, I'll return ...

							return;
						}

						if(currentRecord.updateStatus === updateStatus.DONE) {
							this.current++;
							this._updateCurrentRecord();
						}

						currentRecord.updateStatus = updateStatus.INPROGRESS;

// TODO: Stopped here:
//      data to send (table name, pk)?
//      handle case when table has no calculated fields ....
//      handle network connection errors ....
//      
						launchAjaxUpdateCalculatedRecord({
							data: {

							},
							error: function() {
								currentRecord.updateStatus = updateStatus.ERROR;
							},
							success: function(resp) {
								currentRecord.updateStatus = updateStatus.DONE;
							},
							complete: function() {
								this.current++;
								this._updateCurrentRecord();
							}
						});
					},

					start: function() {
						// cases to skip
						if(
							this.running || // already running?
							!this.records.length || // no records?
							!this.hasCalculatedFields || // no calc fields?
							(this.current !== null && this.current >= this.last) // batch already processed?
						) return;

						// batch stopped?
						if(this.current === null) this.current = this.first;

						// if this.current is not null and is < this.last
						// it means that the batch might have been paused
						// and the user wants to resume it now
						
						this.running = true;
						this._updateCurrentRecord();
					},

					stop: function() {
						this.running = false;
						this.current = null;
						for(var rec in this.records) {
							this.records[rec].updateStatus = updateStatus.PENDING;
						}
					},

					pause: function() {
						this.running = false;
					}
				};
			}

			var BatchRecord = function(pk) {
				return {
					pk: pk,
					updateStatus: updateStatus.PENDING,
				};
			}

			var populateBatch = function(batch, tbls, recCounts) {
				batch.length = 0;
				var i = 0;

				for(var t in tbls) {
					batch.push(BatchTable(t));
					batch[i].last = Math.min(batch[i].last, recCounts[t]);
					i++;
				}	
			}

			<?php
			return ob_get_clean();
		}

		private function js_init() {
			ob_start(); ?>
			var tables = <?php echo json_encode($this->tables); ?>;
			var $t = <?php echo json_encode($this->t); ?>;
			var tableRecordCount = <?php echo json_encode($this->table_records); ?>;
			var maxBatchSize = <?php echo $this->max_batch_size; ?>;
			
			// array of BatchTable objects
			var currentBatch = [];

			$j(function() {
				populateBatch(currentBatch, tables, tableRecordCount);
				populateTablesList();
				handleTableSelection();
				handleStart();
				handleStop();
			})
			<?php
			return ob_get_clean();
		}

/*
 * TODO:
 * ajax-load record IDs
 * next/prev buttons to the right/left of each progress bar to shift batch right/left by same as current batch size
 * start, pause buttons
 */

		private function js_handleTableSelection() {
			ob_start(); ?>
			var handleTableSelection = function() {

			}
			<?php
			return ob_get_clean();
		}

		private function js_handleStart() {
			ob_start(); ?>
			var handleStart = function() {

			}
			<?php
			return ob_get_clean();
		}

		private function js_handleStop() {
			ob_start(); ?>
			var handleStop = function() {

			}
			<?php
			return ob_get_clean();
		}

		private function process_request($req, $tn, $start) {
			switch ($req) {
				case 'js-functions':
					$this->js_functions();
					break;
				case 'value':
					# code...
					break;
				
				case 'skeleton':
				default:
					$this->skeleton();
					break;
			}
		}

		private function skeleton() {
			$Translation = $this->t;
			ob_start();
			$GLOBALS['page_title'] = $this->t['update calculated fields'];
			include("{$this->curr_dir}/incHeader.php");
			?>

			<script src="<?php echo basename(__FILE__); ?>?request=js-functions"></script>
			<style>
				td .description { max-height: 4em; width: 100%; overflow: auto; }
			</style>

<!-- ************************************************************** -->
			<a data-toggle="collapse" data-target="ul.todo" class="todo btn btn-info btn-block btn-lg vspacer-lg">TO DO</a>
			<style>
				.todo{
					max-width: 80rem;
					margin-left: auto;
					margin-right: auto;
				}
				ul.todo {
					font-size: 1.75rem;
					margin-bottom: 5rem;
					padding: 2rem 5rem 1rem;
					margin-top: -1rem;
				}
				ul.todo li:before {
					content: "\e157";
					font-family: 'Glyphicons Halflings';
					font-size: 1.5rem;
					float: left;
					margin-top: 0rem;
					margin-left: -2rem;
					color: #999;
				}
				ul.todo li.done {
					text-decoration: line-through;
				}
				ul.todo li.done:before {
					content: "\e067";
				}
				ul.todo li {
					line-height: 2.625rem;
					margin-bottom: 2rem;
					display: block;
				}
			</style>
			<ul class="todo collapse bg-info">
				<li class="done"> Remove range and progress bar and all associated JS code.</li>
				<li class="not-done">
					Don't give users precise control over start and end ... just
					a preset batch size of 1000 records, loading the 1st 1000 PKs
					of each table into a <code>currentBatch</code> data structure.
				</li>
				<li class="not-done">
					<b>Optimal UI for presenting the above?</b> 
					Perhaps 2 progress bars: one in the 'Update progress' column, representing
					overall progress based on total record count, and a second in
					the 'Current batch' column, showing progress of current batch (of 1000 records max).
				</li>
				<li class="not-done"> 
					Cog icon in 'Current batch' column that opens a modal allowing user to
					specify start and end of batch (end - start must be 1000 or less)
				</li>
				<li class="not-done"> 
					Action buttons for each table: start/resume, pause, stop
					(
						<i class="glyphicon glyphicon-play text-success"></i> 
						<i class="glyphicon glyphicon-pause text-warning"></i> 
						<i class="glyphicon glyphicon-stop text-danger"></i> 
					)
				</li>
				<li class="not-done"> Mass buttons (same as above) that apply to all selected tables.</li>
				<li class="not-done">
					<i class="glyphicon glyphicon-play text-success"></i>
					starts/resumes a batch. Clicking it when a batch is in progress
					has no effect.
				</li>
				<li class="not-done">
					<i class="glyphicon glyphicon-pause text-warning"></i>
					pauses a batch (no more ajax requests are launched for that batch).
					Clicking it when a batch is paused has no effect.
					Batch progress pointer is <i>not</i> reset on pause.
				</li>
				<li class="not-done">
					<i class="glyphicon glyphicon-stop text-danger"></i>
					stops a batch (no more ajax requests are launched for that batch).
					Clicking it when a batch is neither in progress nor paused has no effect.
					Batch progress pointer is <i><b>reset</b></i> on stop.
				</li>
				<li class="not-done">
					<code>currentBatch</code> structure?
					<pre>
// how do we want to handle batches?

populateCurrentBatch(currentBatch, tables, tableRecordCount);
currentBatch[i].start() 
	// if cb[i].current === null, set to cb[i].first
	// continues requests from cb[i].current as follows:
	// sets cb[i].running to true
	// *** launch ajax update request for cb[i].records[cb[i].current  - 1].pk
		// if !cb[i].running:
			// stop/return
		// if cb[i].current >= cb[i].last:
			// cb[i].running = false
			// stop/return
		// if cb[i].records[cb[i].current - 1].updateStatus === PENDING:
			// ?????????????????
			// i'm not sure if this cause would happen
			// but if it does, I guess we should stop/return?
			// or move to next, like DONE?
			// ?????????????????
		// if cb[i].records[cb[i].current - 1].updateStatus === DONE:
			// cb[i].current++
			// *** launch ajax update request for cb[i].records[cb[i].current - 1].pk
		// cb[i].records[cb[i].current - 1].updateStatus = PENDING
		// when cb[i].current is complete:
			// cb[i].records[cb[i].current - 1].updateStatus = DONE/ERROR
			// cb[i].current++
			// *** launch ajax update request for cb[i].records[cb[i].current - 1].pk

<s>currentBatch[i].pause()</s>
	// pauses requests
	// cb[i].running = false

<s>currentBatch[i].stop()</s>
	// stops and resets requests
	// cb[i].running = false
	// cb[i].current = null
	// for each record in cb[i].records: record.updateStatus = PENDING

//to retry failed requests:
	// currentBatch[i].stop()
	// currentBatch[i].start()
					</pre>
				</li>
				<li class="not-done">
					<code>batches</code> structure?
				</li>
			</ul>
<!-- ************************************************************** -->

			<div id="tables-list"></div>

			<?php
			echo ob_get_clean();
			include("{$this->curr_dir}/incFooter.php");
		}

	}
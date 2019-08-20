<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\CustomerEmail;
use App\Models\Department;
use App\Models\KayakoSyncSetting;
use App\Models\KayakoSyncLog;
use App\Models\State;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\TicketEmail;
use App\Services\AdministrativeServices;
use DB;
use Log;

class SyncKayakoTicketsToCrmCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kayako:sync-to-crm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync new Kayako tickets to CRM database';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle($trx = null)
    {
        DB::beginTransaction();
        $sync_status = 0;

        try { // General try catch
            $this->reconnectDatabasesIfLost(); // try to fix bugeye error (Error while sending STMT_PREPARE packet. PID=xxx)
            $sync_current_running_offset = KayakoSyncSetting::getSyncRunningOffset();

            if(KayakoSyncSetting::checkIsSyncStillRunning() && is_null($trx)) // disallow kayako sync run if previous is still running
            {
                $data_msg = "Kayako:tickets sync started but failed. Previous kayako sync is still running.\nEnding kayako sync.\n";
                Log::info($data_msg);

                if(KayakoSyncLog::reachedKayakoSyncFailedAttempts($sync_current_running_offset))
                {
                    $this->reconnectDatabasesIfLost();
                    KayakoSyncSetting::updateSyncStatus(KayakoSyncSetting::SYNC_NOT_RUNNING);
                }

                return KayakoSyncLog::createLog([
                    'data' => $data_msg,
                    'log_level' => KayakoSyncLog::LOG_LEVEL_ERROR,
                    'endpoint' => $sync_current_running_offset,
                    'taken_attempts' => 1,
                ]);
            }
            else
            {
                $sync_status = KayakoSyncSetting::SYNC_RUNNING;
                $this->reconnectDatabasesIfLost(); // try to fix bugeye error (Error while sending STMT_PREPARE packet. PID=xxx)

                if(is_null($trx))
                {
                    $sync_trx_log = KayakoSyncLog::createLog([
                        'data' => "KAYAKO:tickets sync started...\n",
                        'log_level' => KayakoSyncLog::LOG_LEVEL_INFO,
                        'endpoint' => $sync_current_running_offset,
                        'taken_attempts' => 1,
                        'status' => KayakoSyncSetting::SYNC_CONCLUDED,
                        'started_at' => create_datetime_format('Y-m-d H:i:s'),
                        'ended_at' => create_datetime_format('Y-m-d H:i:s'),
                    ]);
                }
                else
                {
                    $sync_trx_log = $trx;
                    $sync_trx_log->data = $sync_trx_log->data."Reattempting ...\n";
                    $sync_trx_log->save();
                    Log::info("Kayako sync reattempting :: " . $sync_trx_log->name);
                }

                Log::info("Kayako sync started :: " . $sync_trx_log->name);

                try { // Kayako Try Catch
                    $kayako = app()->make('KayakoLib');

                    $departments = self::getDepartments();
                    $statuses = self::getStatuses();

                    $sync_max_items = KayakoSyncSetting::getSyncMaxItems();
                    $sync_start_date = strtotime(KayakoSyncSetting::getSyncStartDate());
                    $sync_running_offset = KayakoSyncSetting::getSyncRunningOffset();

                    $ky_tickets = \kyTicket::getAll(
                                        $departments, // all departments
                                        $statuses, // ticket statuses Open & In Progress
                                        [], // including all owner staffs
                                        [], // including all users (customers in crm)
                                        $sync_max_items,
                                        $sync_running_offset
                                    );

                    $ky_tickets = $ky_tickets->filterByCreationMode([
                                        \kyTicket::CREATION_MODE_EMAIL,
                                        \kyTicket::CREATION_MODE_SUPPORTCENTER,
                                        \kyTicket::CREATION_MODE_STAFFCP,
                                        \kyTicket::CREATION_MODE_SITEBADGE,
                                    ])
                                    ->filterByCreationTime([ '>=', $sync_start_date ])
                                    ->orderByCreationTime();

                    $sync_trx_log->data = $sync_trx_log->data.\kyConfig::get()->getRestClient()->getRestToKayako()."\n";
                    $sync_trx_log->data = $sync_trx_log->data."Total retrieved tickets: ".$ky_tickets->count()."\n";
                    $sync_trx_log->total_retrieved = $ky_tickets->count();
                    $sync_trx_log->status = KayakoSyncSetting::SYNC_RUNNING;
                    $sync_trx_log->started_at = create_datetime_format('Y-m-d H:i:s');
                    $sync_trx_log->save();

                    $total_sync = 0;

                    if($ky_tickets->count() > 0)
                    {
                        foreach($ky_tickets as $ky_ticket)
                        {
                            if( !Ticket::checkIfKayakoTicketExists($ky_ticket->getId()) )
                            {
                                $ky_ticket_user = $ky_ticket->getUser();
                                $sync_trx_log->data = $sync_trx_log->data.\kyConfig::get()->getRestClient()->getRestToKayako()."\n";
                                $ky_ticket_posts = $ky_ticket->getPosts();
                                $sync_trx_log->data = $sync_trx_log->data.\kyConfig::get()->getRestClient()->getRestToKayako()."\n";
                                $sync_trx_log->save();

                                $ticket = self::createCrmTicket($ky_ticket, $ky_ticket_user, $ky_ticket_posts);
                                $total_sync++;
                            }
                        }

                        KayakoSyncSetting::updateSyncLastSuccessfulOffset($sync_running_offset);
                        KayakoSyncSetting::incrementRunningOffset(); // increment offset for the next sync
                    } else {
                        // Attempt to automate Kayako Ticket Resync fix
                        (new AdministrativeServices())->resyncKayakoTicketSyncing(0);
                    }

                    $sync_trx_log->data = $sync_trx_log->data."Total synced tickets: ".$total_sync."\nEnded kayako syncing.\n";
                    $sync_trx_log->total_sync = $total_sync;
                    $sync_trx_log->ended_at = create_datetime_format('Y-m-d H:i:s');
                    $sync_trx_log->status = KayakoSyncSetting::SYNC_CONCLUDED;
                    $sync_trx_log->save();

                    $sync_status = KayakoSyncSetting::SYNC_NOT_RUNNING; // reset status to not running (sync concluded)
                    Log::info("Kayako sync concluded :: " . $sync_trx_log->name);
                } catch(\kyException $ex) { // Kayako Try Catch
                    if((int) $sync_trx_log->taken_attempts >= KayakoSyncSetting::getSyncMaxAttempts())
                    {

                        $sync_trx_log->data = $sync_trx_log->data.$ex->getMessage()."\n";
                        $sync_trx_log->ended_at = create_datetime_format('Y-m-d H:i:s');
                        $sync_trx_log->status = KayakoSyncSetting::SYNC_ERROR;
                        $sync_trx_log->save();

                        $sync_status = KayakoSyncSetting::SYNC_NOT_RUNNING; // reset status to not running (sync error occurred)
                        Log::info("Kayako sync error occurred :: " . $sync_trx_log->name);
                        Log::error("error log: ".$ex->getMessage(), $ex->getTrace());
                    }
                    else
                    {
                        $sync_trx_log->increment('taken_attempts');
                        $this->handle($sync_trx_log);
                    }
                }
            }

            $this->reconnectDatabasesIfLost();
            KayakoSyncSetting::updateSyncStatus($sync_status);
            DB::commit();
        } catch(Exception $ex) { // General try catch
            DB::rollback();
            return false;
        }
    }

    private static function createCrmTicket($ky_ticket, $ky_ticket_user, $ky_ticket_posts)
    {
        // preparing customer of ticket
        $ky_user_id = $ky_ticket_user->getId();
        $ky_user_emails = collect($ky_ticket_user->getEmails())->map(function($value, $key) {
            return new CustomerEmail([
                'email_address' => $value,
                'is_default' => ($key == 0),
            ]);
        });
        $ky_customer_data = [
            'customer_id' => $ky_user_id,
            'name' => $ky_ticket_user->getFullName(),
            'emails' => $ky_user_emails,
            'email_used' => $ky_ticket->getEmail(),
        ];

        $customer = Customer::findOrCreateKayakoCustomer($ky_customer_data);

        $department_id = $ky_ticket->getDepartmentId();
        $ticket_type_id = TicketType::getTicketTypeIdByDepartmentId($department_id);

        // preparing ticket data for insertion
        $ticket_data = [
            'kayako_ticket_id' => $ky_ticket->getId(),
            'display_id' => $ky_ticket->getDisplayId(),
            'department_id' => $department_id,
            'priority_id' => $ky_ticket->getPriorityId(),
            'type_id' => $ky_ticket->getTypeId(),
            'customer_id' => $customer->id,
            'ip_address' => $ky_ticket->getIPAddress(),
            'ticket_type_id' => $ticket_type_id,
            'state_id' => $ky_ticket->getStatusId(),
            'name' => $ky_ticket->getSubject(),
            'description' => $ky_ticket->getContents(),
            'kayako_staff_id' => $ky_ticket->getOwnerStaffId(),
            'creation_mode' => $ky_ticket->getCreationMode(),
            'reported_at' => $ky_ticket->getCreationTime(),
            'created_at' => $ky_ticket->getCreationTime(),
            'updated_at' => $ky_ticket->getLastActivity(),
        ];

        $ticket = Ticket::create($ticket_data);

        // preparing ticket emails (ticket post in kayako) to link on ticket created
        $ky_ticket_emails = [];

        foreach($ky_ticket_posts as $post)
        {
            $creator_type = $post->getCreatorType();
            $status = $creator_type == \kyTicketPost::CREATOR_USER ? TicketEmail::RECEIVED : TicketEmail::SUCCESS;

            $ky_ticket_emails[] = new TicketEmail([
                'customer_id' => $customer->id,
                'ky_ticket_post_id' => $post->getId(),
                'email_address' => $post->getEmail(),
                'email_subject' => $post->getSubject(),
                'email_content' => $post->getContents(),
                'email_date' => $post->getDateline(),
                'created_at' => $post->getDateline(),
                'status' => $status,
                'creator' => $creator_type,
            ]);
        }

        if(count($ky_ticket_emails) > 0)
        {
            $ticket->emailTickets()->saveMany($ky_ticket_emails);
        }

        return $ticket;
    }

    private static function getKYPreparedFilterDisplayIds()
    {
        $display_ids = Ticket::select('display_id')->whereNotNull('kayako_ticket_id')->pluck('display_id');
        $ky_prepared_filter_display_ids = $display_ids->map(function($value, $key) {
            return [
                '!=', $value
            ];
        });

        return $ky_prepared_filter_display_ids->toArray();
    }

    private static function getStatuses()
    {
        $statuses = State::getKYStatusIdsNotResolved();

        if(empty($statuses))
        {
            $statuses = \kyTicketStatus::getAll()->filterByMarkAsResolved([0]);
        }

        return $statuses;
    }

    private static function getDepartments()
    {
        $departments = Department::getDepartmentIds();

        if(empty($departments))
        {
            $departments = \kyDepartment::getAll();
        }

        return $departments;
    }

    private function reconnectDatabasesIfLost()
    {
        if(DB::connection()->getDatabaseName() != env('DB_DATABASE')) {
            DB::connection()->reconnect();
        }

        if(DB::connection('mysql_logs')->getDatabaseName() != env('LOG_DB_DATABASE')) {
            DB::connection('mysql_logs')->reconnect();
        }
    }
}
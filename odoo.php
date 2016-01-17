<?php
require_once("backend/odoo/config.php");

require_once('lib/default/diffbackend/diffbackend.php');
require_once('backend/odoo/ripcord/ripcord.php');

class BackendOdoo extends BackendDiff {
  protected $uid = false;
  protected $password = false;
  protected $models = null;
  protected $partnerID = false;

  public function GetSupportedASVersion() {
        return ZPush::ASV_14;
  }

  public function Logon($username, $domain, $password) {
    $common = ripcord::client(ODOO_SERVER . '/xmlrpc/2/common');
    $this->uid = $common->authenticate(ODOO_DB, $username, $password, []);
    $this->password = $password;

    if ($this->uid) {
      $this->models = ripcord::client(ODOO_SERVER . '/xmlrpc/2/object');
      $this->models->_throwExceptions = true;
      $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $password,
        'res.users', 'search_read', [[
          ['id', '=', $this->uid]
        ]], [
          'fields' => ['partner_id']
      ]);

      if (count($partners) == 0) {
        return false;
      }

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::Logon: $partners = (' . print_r($partners, true)) . ')';

      $this->partnerID = $partners[0]['partner_id'][0];
      ZLOG::Write(LOGLEVEL_INFO, 'Odoo:Logon: Logged in with partner/user id ' . $this->partnerID . '/' . $this->uid);
      return true;
    }
    return false;
  }

	public function Logoff() {
    return true;
  }

	public function SendMail($sm) {
    return false;//not implemented
  }

	public function GetWasteBasket() {
    return false;//not implemented
  }

	public function GetAttachmentData($attname) {
    return false;//not implemented
  }

	public function GetFolderList() {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetFolderList()');
    $folders = [];
    $folders[] = $this->StatFolder('calendar');
    $folders[] = $this->StatFolder('partners');
    $folders[] = $this->StatFolder('tasks');

    return $folders;
  }

  public function StatFolder($id) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::StatFolder(' . $id . ')');
    $folder = $this->GetFolder($id);
    $stat = [];
    $stat["id"] = $id;
    $stat["parent"] = $folder->parentid;
    $stat["mod"] = $folder->displayname;

    return $stat;
  }

  public function GetFolder($id) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetFolder(' . $id . ')');
    if ($id == 'calendar') {
      $folder = new SyncFolder();
      $folder->serverid = $id;
      $folder->parentid = "0";
      $folder->displayname = "Calendar";
      $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
      return $folder;
    }
    else if ($id == 'partners') {
      $folder = new SyncFolder();
      $folder->serverid = $id;
      $folder->parentid = "0";
      $folder->displayname = "Partners";
      $folder->type = SYNC_FOLDER_TYPE_CONTACT;
      return $folder;
    }
    else if ($id == 'tasks') {
      $folder = new SyncFolder();
      $folder->serverid = $id;
      $folder->parentid = "0";
      $folder->displayname = "Tasks";
      $folder->type = SYNC_FOLDER_TYPE_TASK;
      return $folder;
    }
    return false;
  }

	public function ChangeFolder($folderid, $oldid, $displayname, $type){
    return false;
  }

	public function DeleteFolder($id, $parentid){
    return false;
  }

	public function GetMessageList($folderid, $cutoffdate) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList(' . $folderid . ')');

    $cutoffdate = date('c', $cutoffdate);
    $messages = [];

    if ($folderid == 'calendar') {
      try {
        $events = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
          'calendar.event', 'search_read', [['|', '&',
            ['user_id', '=', $this->uid],
            ['partner_ids', 'in', [$this->partnerID]],
            ['write_date', '>=', $cutoffdate]
          ]], [
            'fields' => ['id', 'write_date']
          ]
        );
      }
      catch (Exception $e) {
        if ($e->faultCode == 2) {
          ZLog::Write(LOGLEVEL_WARN, 'Error retrieving events.
            Please make sure that the calendar module is installed');
        }
      }

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $events = (' . print_r($events, true)) . ')';

      foreach($events as $event) {
        $messages[] = [
          'id' => 'event_' . $event['id'],
          'mod' => strtotime($event['write_date']),
          'flags' => 1
        ];
      }
    }
    else if ($folderid == 'partners') {
      $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'res.partner', 'search_read', [[
          ['is_company', '!=', 'True'],
          ['write_date', '>=', $cutoffdate]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $partners = (' . print_r($partners, true)) . ')';

      foreach($partners as $partner) {
        $messages[] = [
          'id' => 'partner_' . $partner['id'],
          'mod' => strtotime($partner['write_date']),
          'flags' => 1
        ];
      }
    }
    else if ($folderid == 'tasks') {
      try {
        $tasks = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
          'project.task', 'search_read', [[
            ['user_id', '=', $this->uid],
            ['write_date', '>=', $cutoffdate]
          ]], [
            'fields' => ['id', 'write_date']
          ]
        );
      }
      catch (Exception $e) {
        if ($e->faultCode == 2) {
          ZLog::Write(LOGLEVEL_WARN, 'Error retrieving tasks.
            Please make sure that the project module is installed');
        }
      }

      ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $tasks = (' . print_r($tasks, true)) . ')';

      foreach($tasks as $task) {
        $messages[] = [
          'id' => 'task_' . $task['id'],
          'mod' => strtotime($task['write_date']),
          'flags' => 1
        ];
      }
    }

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessageList: $messages = (' . print_r($messages, true)) . ')';
    return $messages;
  }


	public function GetMessage($folderid, $id, $contentparameters) {
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage(' . $folderid . ', ' . $id . ', ...)');

    if ($folderid == 'calendar') {
      return $this->GetEvent($id, $contentparameters);
    }
    else if ($folderid == 'partners') {
      return $this->GetPartner($id, $contentparameters);
    }
    else if ($folderid == 'tasks') {
      return $this->GetTask($id, $contentparameters);
    }

    return false;
  }

  protected function GetEvent($id, $contentparameters) {
    $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

    $events = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'calendar.event', 'search_read', [['|', '&',
        ['user_id', '=', $this->uid],
        ['partner_ids', 'in', [$this->partnerID]],
        ['id', '=', intval(substr($id, 6))]
      ]],
      ['fields' => []]
    );
    if (!count($events)) {
      $message = new SyncAppointment();
      $message->deleted = 1;
      return $message;
    }
    $event = $events[0];

    $users = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'res.users', 'search_read', [[
        ['id', '=', $event['user_id'][0]]
      ]], [
        'fields' => ['email']
      ]
    );
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $users = (' . print_r($users, true)) . ')';
    if (!count($users)) return false;
    $user = $users[0];

    $attendees = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'calendar.attendee', 'read', [$event['attendee_ids']], [
        'fields' => [
          'cn', 'email', 'state'
        ]
      ]
    );
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $attendees = (' . print_r($attendees, true)) . ')';

    $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'calendar.event.type', 'read', [$event['categ_ids']], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $categories = (' . print_r($categories, true)) . ')';

    $message = new SyncAppointment();
    $message->uid = $event['id'];
    $message->dtstamp = strtotime($event['write_date']);
    $message->starttime = strtotime($event['start']);
    $message->timezone = $this->getUTC();
    $message->subject = $event['name'];

    if (count($attendees) != 0) {
      $message->organizername = $event['user_id'][1];
      $message->organizeremail = $user['email'];
    }

    $message->location = $event['location'];
    $message->endtime = strtotime($event['stop']);

    if ($event['recurrency']) {
      $recurrence = new SyncRecurrence();
      switch ($event['rrule_type']) {
        case 'daily':
          $recurrence->type = 0;
          break;
        case 'weekly':
          $recurrence->type = 1;
          break;
        case 'monthly':
          $recurrence->type = 2;
          if ($event['month_by'] == 'day') {
            $recurrence->type = 3;
          }
          break;
        case 'yearly':
          $recurrence->type = 5;
          break;
      }
      $recurrence->until = strtotime($event['final_date']);
      $recurrence->occurrences = intval($event['count']);
      $recurrence->interval = intval($event['interval']);

      //weekly
      if ($recurrence->type == 1) {
        $recurrence->dayofweek = 0;
        if ($event['su']) $recurrence->dayofweek += 1;
        if ($event['mo']) $recurrence->dayofweek += 2;
        if ($event['tu']) $recurrence->dayofweek += 4;
        if ($event['we']) $recurrence->dayofweek += 8;
        if ($event['th']) $recurrence->dayofweek += 16;
        if ($event['fr']) $recurrence->dayofweek += 32;
        if ($event['sa']) $recurrence->dayofweek += 64;
      }

      //monthly
      if ($recurrence->type == 2) {
        $recurrence->dayofmonth = intval($event['day']);
      }

      //monthly on nth day
      if ($recurrence->type == 3) {
        $recurrence->dayofweek = 0;

        switch ($event['byday']) {
          case '1':
          case '2':
          case '3':
          case '4':
          case '5':
            $recurrence->weekofmonth = intval($event['byday']);
            break;
          case '-1':
            $recurrence->weekofmonth = 5;
            break;
        }

        switch ($event['week_list']) {
          case 'SU':
            $recurrence->dayofweek += 1;
            break;
          case 'MO':
            $recurrence->dayofweek += 2;
            break;
          case 'TU':
            $recurrence->dayofweek += 4;
            break;
          case 'WE':
            $recurrence->dayofweek += 8;
            break;
          case 'TH':
            $recurrence->dayofweek += 16;
            break;
          case 'FR':
            $recurrence->dayofweek += 32;
            break;
          case 'SA':
            $recurrence->dayofweek += 64;
            break;
        }
      }

      if ($recurrence->type == 5) {
        $start_date = strtotime($event['start_date']);
        $recurrence->monthofyear = intval(date('n', $start_date));
      }

      $message->recurrence = $recurrence;
    }

    switch ($event['class']) {
      case 'public':
        $message->sensitivity = 0;
        break;
      case 'private':
        $message->sensitivity = 2;
        break;
      case 'confidential':
        $message->sensitivity = 3;
        break;
    }

    switch ($event['show_as']) {
      case 'free':
        $message->busystatus = 0;
        break;
      case 'busy':
        $message->busystatus = 2;
        break;
    }

    $message->alldayevent = $event['allday'];
    $message->reminder = 30;//TODO
    $message->meetingstatus = count($attendees) == 0 ? 0 : 1;

    $message->attendees = array_map(function ($attendee) use ($message) {
      $syncattendee = new SyncAttendee();
      $syncattendee->email = $attendee['email'];
      $syncattendee->name = $attendee['cn'];

      $syncattendee->attendeetype = 1;
      switch ($attendee['state']) {
        case 'needsAction':
          $syncattendee->attendeestatus = 5;
          break;
        case 'tentative':
          $syncattendee->attendeestatus = 2;
          break;
        case 'declined':
          $syncattendee->attendeestatus = 4;
          break;
        case 'accepted':
          $syncattendee->attendeestatus = 3;
          break;
        default:
          $syncattendee->attendeestatus = 0;
      }

      return $syncattendee;
    }, $attendees);

    $body = $event['description'];
    $message->bodytruncated = false;
    if(strlen($body) > $truncsize) {
      $body = Utils::Utf8_truncate($body, $truncsize);
      $message->bodytruncated = true;
    }
    $message->body = str_replace("\n", "\r\n", str_replace("\r", "", $body));
    $message->asbody = new SyncBaseBody();

    $message->categories = array_map(function ($category) {
      return $category['name'];
    }, $categories);

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $message = (' . print_r($message, true) . ')');
    return $message;
  }

  protected function GetPartner($id, $contentparameters) {
    $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

    $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'res.partner', 'search_read', [[
        ['is_company', '!=', 'True'],
        ['id', '=', intval(substr($id, 8))]
      ]], [
        'fields' => []
      ]
    );
    if (!count($partners)) {
      $message = new SyncContact();
      $message->deleted = 1;
      return $message;
    }

    $partner = $partners[0];

    $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'res.partner.category', 'read', [$partner['category_id']], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $categories = (' . print_r($categories, true)) . ')';

    $message = new SyncContact();
    $message->birthday = strtotime($partner['birthdate']);
    $message->businesscity = $partner['city'];
    if ($partner['country_id']) $message->businesscountry = $partner['country_id'][1];
    $message->businesspostalcode = $partner['zip'];

    if ($partner['state_id']) $message->businessstate = $partner['state_id'][1];
    $message->businessstreet = $partner['street'];

    $message->businessfaxnumber = $partner['fax'];
    $message->businessphonenumber = $partner['phone'];
    if ($partner['company_id']) $message->companyname = $partner['company_id'][1];
    $message->email1address = $partner['email'];
    $message->fileas = $partner['name'];

    $names = preg_split('/\s+/', $partner['name'], 3, PREG_SPLIT_NO_EMPTY);
    if (count($names) == 1) $message->firstname = $names[0];
    if (count($names) == 3) {
      $message->firstname = $names[0];
      $message->middlename = $names[1];
      $message->lastname = $names[2];
    }
    else if (count($names) == 2) {
      $message->firstname = $names[0];
      $message->lastname = $names[1];
    }

    $message->jobtitle = $partner['function'];
    $message->title = $partner['title'];
    $message->webpage = $partner['website'];

    $message->categories = array_map(function ($category) {
      return $category['name'];
    }, $categories);

    $body = $partner['comment'];
    $message->bodytruncated = false;
    if(strlen($body) > $truncsize) {
      $body = Utils::Utf8_truncate($body, $truncsize);
      $message->bodytruncated = true;
    }
    $message->body = str_replace("\n", "\r\n", str_replace("\r", "", $body));
    $message->asbody = new SyncBaseBody();

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $message = (' . print_r($message, true) . ')');
    return $message;
  }

  protected function GetTask($id, $contentparameters) {
    $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

    $tasks = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'project.task', 'search_read', [[
        ['user_id', '=', $this->uid],
        ['id', '=', intval(substr($id, 5))]
      ]], [
        'fields' => [
          'stage_id',
          'tag_ids',
          'description',
          'date_last_stage_update',
          'date_deadline',
          'priority',
          'date_start',
          'name'
        ]
      ]
    );
    if (!count($tasks)) {
      $message = new SyncTask();
      $message->deleted = 1;
      return $message;
    }

    $task = $tasks[0];

    $stage = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'project.task.type', 'read', [$task['stage_id'][0]], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $stage = (' . print_r($stage, true)) . ')';
    if (count($stage) == 0) {
      return false;
    }

    $categories = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
      'project.tags', 'read', [$task['tag_ids']], ['fields' => []]);
    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $categories = (' . print_r($categories, true)) . ')';

    $message = new SyncTask();

    $body = $task['description'];
    $message->bodytruncated = false;
    if(strlen($body) > $truncsize) {
      $body = Utils::Utf8_truncate($body, $truncsize);
      $message->bodytruncated = true;
    }
    $message->body = str_replace("\n", "\r\n", str_replace("\r", "", $body));
    $message->asbody = new SyncBaseBody();
    $message->asbody->data = $body;

    $message->complete = 0;
    if ($stage['fold']) {
      $message->complete = 1;
      $message->datecompleted = strtotime($task['date_last_stage_update']);
    }

    if ($task['date_deadline']) {
      $message->duedate = strtotime($task['date_deadline']);
      $message->utcduedate = strtotime($task['date_deadline']);
    }

    $message->importance = intval($task['priority']);
    $message->sensitivity = 0;
    $message->startdate = strtotime($task['date_start']);
    $message->utcstartdate = strtotime($task['date_start']);
    $message->subject = $task['name'];

    ZLog::Write(LOGLEVEL_DEBUG, 'Odoo::GetMessage: $message = (' . print_r($message, true) . ')');
    return $message;
  }

  public function StatMessage($folderid, $id) {
    if ($folderid == 'calendar') {
      $events = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'calendar.event', 'search_read', [[
          ['id', '=', intval(substr($id, 6))]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      if (!count($events)) return false;
      $event = $events[0];

      return [
        'id' => 'event_' . $event['id'],
        'mod' => strtotime($event['write_date']),
        'flags' => 1
      ];
    }
    else if ($folderid == 'partners') {
      $partners = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'res.partner', 'search_read', [[
          ['id', '=', intval(substr($id, 8))]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      if (!count($partners)) return false;
      $partner = $partners[0];

      return [
        'id' => 'partner_' . $partner['id'],
        'mod' => strtotime($partner['write_date']),
        'flags' => 1
      ];
    }
    else if ($folderid == 'tasks') {
      $tasks = $this->models->execute_kw(ODOO_DB, $this->uid, $this->password,
        'project.task', 'search_read', [[
          ['id', '=', intval(substr($id, 5))]
        ]], [
          'fields' => ['id', 'write_date']
        ]
      );

      if (!count($tasks)) return false;
      $task = $tasks[0];

      return [
        'id' => 'task_' . $task['id'],
        'mod' => strtotime($task['write_date']),
        'flags' => 1
      ];
    }

    return false;
  }

	public function ChangeMessage($folderid, $id, $message, $contentParameters) {
    return false;
  }

	public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
    return false;
  }

	public function DeleteMessage($folderid, $id, $contentParameters) {
    return false;
  }

	public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
    return false;
  }

  protected function getUTC() {
    return base64_encode(pack('la64vvvvvvvvla64vvvvvvvvl', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0));
  }
}
?>

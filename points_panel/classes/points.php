<?php
namespace PHPFusion\Points;

use PHPFusion\Points\UserPoint as UserPoints;

class UserPoint extends PointsModel {
    private static $instance = NULL;
    private static $locale = [];
    private $points = [];
    private $bank = [];
    private $group_cache = [];
    public $settings = [];

    public function __construct() {
        include_once POINT_CLASS."templates.php";
        add_to_head("<script type='text/javascript' src='".fusion_get_settings('siteurl')."infusions/points_panel/counts.js'></script>");
        self::$locale = fusion_get_locale("", POINT_LOCALE);
        $this->group_cache = self::PointsGroups();
        iMEMBER ? define("iNP", hash('md5', fusion_get_userdata('user_name'))) : '';
    }

    public static function getInstance() {
        if (self::$instance === NULL) {
            self::$instance = new static();
            self::$instance->points = self::$instance->GetCurrentUser(fusion_get_userdata('user_id'));
            self::$instance->settings = self::$instance->CurrentSetup();
            self::$instance->bank = self::$instance->PointsBank(fusion_get_userdata('user_id'));
        }
       return self::$instance;
    }

    public static function GetCurrentUser($uid = NULL) {

	    $def_point = [
	        'point_id'        => '',
	        'point_user'      => $uid,
	        'point_point'     => 0,
	        'point_increase'  => 0,
	        'point_group'     => '',
	        'point_language'  => LANGUAGE
        ];

        $bind = [
            ':userid'   => $uid,
            ':language' => LANGUAGE
        ];

        $result = dbquery("SELECT *
            FROM ".DB_POINT."
            WHERE point_user=:userid
            ".(multilang_table("PSP") ? " AND point_language=:language" : '')."
            LIMIT 0,1", $bind);

        $point =[];
        if (dbrows($result)){
            $point = dbarray($result);
        }

        $points = array_merge($def_point, $point);

        return $points;
    }

    //Add interest if the system and the Bank are active.
    private function AddBankInterest() {
        if (!empty($this->settings['ps_bank']) && !empty($this->settings['ps_interest'])) {
		    if (!empty($this->bank['interest'])) {
		    	$intrst = FALSE;
		    	$messages = '';
                foreach ($this->bank['interest'] as $value) {
                    if (!empty($value['pb_interest_activ']) && $value['pb_interest_end'] < time()) {
                        $value['pb_interest_activ'] = 0;
                        $messages = self::$locale['PSP_E15'];
                        UserPoints::getInstance()->setPoint($value['pb_user_id'], ['mod' => 1, 'point' => $value['pb_interest_get'], 'messages' => $messages]);
                        dbquery_insert(DB_POINT_BANK, $value, 'update');
		    	        $intrst = TRUE;
                    }
                }
                !empty($intrst) ? addNotice('success', $messages) : '';
		    }
        }
    }

    //Add interest if the system and the Bank are active.
    private function RemovBankloan() {
        if (!empty($this->settings['ps_bank']) && !empty($this->settings['ps_loan'])) {
		    if (!empty($this->bank['loan'])) {
		    	$intrst = FALSE;
		    	$naplouzi = '';
                foreach ($this->bank['loan'] as $key => $value) {
                    if (!empty($value['pb_loan_activ']) && !empty($value['pb_loan_end'])) {
                        $nextday = floor((time() - $value['pb_loan_levont']) / 86400);
                        if ($nextday >= 0) {
                            $tan = ($nextday == 0 ? 1 : ($nextday + 1));
                            $aktnap = ($value['pb_loan_end'] - $tan >= 0 ? $tan : $value['pb_loan_end']);
                            $hit = $value['pb_loan_end'] - $aktnap;
                            $loanend = $hit >= 0 ? $hit : $value['pb_loan_end'];
                            $lev = ($aktnap * $value['pb_loan_reszlet']);
                            $pointinfo = UserPoint::getInstance()->PointInfo($value['pb_user_id'], $lev);
                            $baninfo = UserPoint::getInstance()->PointBan($value['pb_user_id']);
                            //remov ban.
                            if (!empty($this->settings['ps_bank']) && !empty($baninfo)) {
                                if ($pointinfo > 0) {
                                    UserPoints::getInstance()->SetPointBan($value['pb_user_id'], ['ban_mod' => 2, 'ban_stop' => time()]);
                                }
                            }
                            //Ha nincs el?g Pont a Hitel levon?shoz letiltja
                            if ($pointinfo < 0 && empty($baninfo)) {
                                $ban_log = self::$locale['PSP_B59'];
                                UserPoints::getInstance()->SetPointBan($value['pb_user_id'], ['ban_mod' => 1, 'ban_start' => time(), 'ban_stop' => 0, 'ban_text' => $ban_log]);
                            } else {
                                //loan day.
                                if (empty($baninfo)) {
                                    $betet_ki['pb_id'] = $value['pb_id'];
                                    $betet_ki['pb_loan_activ'] = $loanend == 0 ? 0 : 1;
                                    $betet_ki['pb_loan_levont'] = $value['pb_loan_levont']+($tan * 86400);
                                    $betet_ki['pb_loan_end'] = $loanend;
                                    $naplouzi = sprintf(self::$locale['PSP_B60'], $aktnap);

                                    dbquery_insert(DB_POINT_BANK, $betet_ki, 'update');
                                    UserPoints::getInstance()->setPoint($value['pb_user_id'], ['mod' => 2, 'point' => $lev, 'messages' => $naplouzi]);
                                    $intrst = TRUE;
                                }
                            }
                        }
                    }
                }
                !empty($intrst) ? addNotice('success', $naplouzi) : '';
		    }
        }
    }

    public function GetPoint() {
        if (iMEMBER && $this->settings['ps_activ']) {
        	if ($this->points['point_increase'] < time()) {
        	    self::SetDayPoint();
        	}
        	if (!empty($this->settings['ps_bank']) && !empty($this->bank)) {
        	    if (!empty($this->bank['interest'])) {
        	        self::AddBankInterest();
        	    }
        	    if (!empty($this->bank['loan'])) {
        	        self::RemovBankloan();
        	    }
        	}
        }
    }

    private function SetDayPoint() {
        $this->points['point_point'] = empty($this->points['point_id']) ? $this->settings['ps_default'] : $this->points['point_point'] + $this->settings['ps_day'] ;
        $this->points['point_increase'] = (time() + $this->settings['ps_dateadd']);

        dbquery_insert(DB_POINT, $this->points, !empty($this->points['point_id']) ? 'update' : 'save');
        $message = (empty($this->points['point_id']) ? sprintf(self::$locale['PSP_001'], $this->settings['ps_default'], ($this->settings['ps_dateadd']/60/60)) : self::$locale['PSP_002']);
        $daypoint = (empty($this->points['point_id']) ? $this->settings['ps_default'] : $this->settings['ps_day']);
        self::PontMessage(fusion_get_userdata('user_id'), ['point' => $daypoint, 'mod' => 1, 'messages' => $message]);
    }

    public static function PointBan($user) {

        if (isnum($user)) {
            $result = dbquery("SELECT *
                FROM ".DB_POINT_BAN."
                WHERE ban_user_id=:user && (ban_time_start<=:bstart && ban_time_stop>=:bstop) || (ban_time_start<=:b2start && ban_time_stop=:b2stop)"
            , [':user' => $user, ':bstart' => time(), ':bstop' => time(), ':b2start' => time(), ':b2stop' => 0]);
		    if (dbrows($result) || $user == 0 || !iMEMBER) {
                return TRUE;
		    }
            return FALSE;
        }
        return TRUE;
    }
    //add user Bann or remov user bann
    //Bann 1 id user
    //SetPointBan(1, ['ban_mod' => 1, 'ban_start' => '1546421200', 'ban_stop' => '1546423200', 'ban_text' => 'messages'])
    //Un Bann 1 id user
    //SetPointBan(1, ['ban_mod' => 2, 'ban_stop' => '1546422200'])
    public function SetPointBan($user, array $options = []) {

	    if (isnum($user) && $user > 0) {
            $options += $this->default_ban;
	    	$banuser = [
	    	    'ban_id'         => '',
	    	    'ban_user_id'    => $user,
	    	    'ban_time_start' => $options['ban_start'],
	    	    'ban_time_stop'  => $options['ban_stop'],
	    	    'ban_text'       => $options['ban_text'],
	    	    'ban_language'   => LANGUAGE
	    	];
            if ($options['ban_mod'] == 2) {
            	$banus = dbarray(dbquery("SELECT * FROM ".DB_POINT_BAN." WHERE ban_user_id=:userid", [':userid' => (int)$user]));
            	$banuser['ban_id'] = $banus['ban_id'];
            	$banuser['ban_time_start'] = $banus['ban_time_start'];
            	$banuser['ban_time_stop'] = (time() - 2);
            	$banuser['ban_text'] = $banus['ban_text'];
            }

	    	dbquery_insert(DB_POINT_BAN, $banuser, $options['ban_mod'] == 1 ? 'save' : 'update');
	    	$subject = self::$locale['PSP_A00'];
	    	$message = $options['ban_mod'] == 1 ? showdate("%Y.%m.%d - %H:%M", $banuser['ban_time_stop']).self::$locale['PSP_A03'] : showdate("%Y.%m.%d - %H:%M", $banuser['ban_time_stop']).self::$locale['PSP_A04'];
	    	send_pm($user, 1, $subject, $message, "y");
	    	addNotice('success', $options['ban_mod'] == 1 ? self::$locale['PSP_A01'] : self::$locale['PSP_A02']);
	    }
    }

	public static function PointPlace($user = 0) {
        $user = ((isnum($user) && $user != 0) ? $user : fusion_get_userdata("user_id"));
        $bind = [
            ':point'    => self::PointInfo($user, ""),
            ':language' => LANGUAGE
        ];
        if (!self::PointBan($user)) {
            $place = dbcount("(*)+1", DB_POINT, "point_point>:point".(multilang_table("PSP") ? " AND point_language=:language" : '')."", $bind);
            return $place;
        }
        return FALSE;
	}

	public static function PointInfo($user, $pont = 0) {
        $bind = [
            ':userid'   => $user,
            ':language' => LANGUAGE
        ];

        $result = dbquery("SELECT point_point
            FROM ".DB_POINT."
            WHERE point_user = :userid
            ".(multilang_table("PSP") ? " AND point_language = :language" : '')."
            LIMIT 0,1", $bind
        );

        if (dbrows($result)) {
            $pont = dbresult($result, 0) - $pont;

            //if (!self::PointBan($user)){
                //if not banned user
                return $pont;
            //}
            //if banned user
           // return FALSE;
        }
        //if not user
        return FALSE;
    }

	protected static function PontDiary($inf) {
        $resultQuery = "SELECT *
            FROM ".DB_POINT_LOG."
            WHERE ".$inf['where'].
            $inf['order'].
            $inf['limit'];
        $result = dbquery($resultQuery, $inf['bind']);
        return dbarray($result);
	}

	private function PontMessage($user = NULL, array $options = []) {

        $options += $this->default_options;
        $diary = [
            'log_id'       => '',
            'log_user_id'  => $user,
            'log_pmod'     => $options['mod'],
            'log_date'     => time(),
            'log_descript' => $options['messages'],
            'log_point'    => $options['point']
        ];
        dbquery_insert(DB_POINT_LOG, $diary, 'save');
    }

	public function setPoint($user = NULL, array $options = []) {

		$user = ($user ? $user : fusion_get_userdata('user_id'));

        $options += $this->default_options;
		$pointmod = self::GetCurrentUser($user);
		if (!empty($this->settings['ps_activ'])) { //activ a system..
			if (!$this->PointBan($user)) {  //if not banned user
			    if ($this->pointTime($user, $options) == 0) { //time test,if there is no time here did not go
                    if (empty($this->settings['ps_pricetype']) && empty($options['pricetype']) && $this->settings['ps_unitprice'] > 0) { //unit price
                        $options['point'] = $this->settings['ps_unitprice'];
                    }

                    $multiplier = $this->pointHollyday();
                    if ($multiplier > 1 && empty($options['hollyday'])) {
                        $options['messages'] = $options['messages'].self::$locale['PONT_317'];
                        $options['point'] = ($options['point'] * ($options['mod'] == 1 ? $multiplier : 1));
                    }
                    $pointmod['point_point'] = $pointmod['point_point'] + ($options['mod'] == 1 ? $options['point'] : $options['point'] * (-1));

                    dbquery_insert(DB_POINT, $pointmod, 'update');

                    if ($this->settings['ps_autogroup']) {
                    	self::PointsGroupsform($pointmod, $this->group_cache, $pointmod['point_point']);
                    }
                    self::PontMessage($user, $options);
			    }
			}
		}
	}

    private function pointHollyday() {
        $point_hollyday = explode(',', $this->settings['ps_holidays']);
        $multiplier = ($this->settings['ps_holiday'] > 1 ? (in_array(date("m").date("d"), $point_hollyday) ? $this->settings['ps_holiday'] : 1) : 1);
    	return $multiplier;
    }

    private function pointTime($user, $options) {
        $options += $this->default_options;
        $bind = [
            ':userid'   => $user,
            ':mod'      => $options['mod'],
            ':date'     => (time() - $options['addtime']),
            ':addnaplo' => $options['messages']
        ];

        $resultQuery = "SELECT log_id
            FROM ".DB_POINT_LOG."
            WHERE log_user_id=:userid AND log_pmod=:mod AND log_date>:date AND log_descript=:addnaplo
            ORDER BY log_date DESC
        ";

		$result = dbquery($resultQuery, $bind);
		return dbrows($result);
	}

	private function pointListMenu(){

        $lstmn = [];

        $bind = [
            ':level'    => fusion_get_userdata('user_level'),
            ':level1'   => fusion_get_userdata('user_level'),
            ':userId'   => fusion_get_userdata('user_id'),
            ':language' => LANGUAGE
        ];

        $listQuery = "SELECT *
            FROM ".DB_POINT_INF."
            WHERE ".(multilang_table("PSP") ? "pi_language=:language AND " : '')."
            (pi_user_id='0' AND pi_user_access >= :level) OR
            (pi_user_id = :userId AND pi_user_access >= :level1)
            ORDER BY pi_user_id ASC, pi_title ASC";

        $result = dbquery($listQuery, $bind);

        while ($gmenu = dbarray($result)) {
        	$plink = iADMIN && ($gmenu['pi_user_access'] == USER_LEVEL_SUPER_ADMIN || $gmenu['pi_user_access'] == USER_LEVEL_ADMIN) ? $gmenu['pi_link'].fusion_get_aidlink() : $gmenu['pi_link'];
            $lstmn[$plink] = $gmenu['pi_title'];
	    }

		$top = form_select('pont_jump', '', '', [
		    'options'     => $lstmn,
		    'inline'      => TRUE,
		    'inner_width' => '170px',
		    'allowclear'  => TRUE,
		    'placeholder' => self::$locale['choose'],
		    'class'       => 'pull-center'
		]);

        add_to_jquery("
            $('#pont_jump').change(function() {
                window.location.href = $(this).val();
            });
	    ");

	    return $top;
	}

    public function DisplayPoint() {
        $multiplier = $this->pointHollyday();
		$diary = [
			'where' => 'log_user_id=:userid',
			'order' => ' ORDER BY log_date DESC',
			'limit' => ' LIMIT 0,1',
			'bind' => [
				':userid'   => fusion_get_userdata('user_id'),
				],
			];
		$message = self::PontDiary($diary);

        $info = [
    		'opentable' => "<i class='fa fa-star-o fa-lg m-r-10'></i>".self::$locale['PSP_M10'],
    	    'id'        => $this->points['point_user'],
    		'activ'     => $this->settings['ps_activ'],
    		'message'   => empty($this->settings['ps_activ']) ? self::$locale['PSP_009'] : '',
    		'pricetype' => empty($this->settings['ps_pricetype']) ? sprintf(self::$locale['PSP_010'], ($this->settings['ps_unitprice'])) : '',
    		'holiday'   => ($this->settings['ps_holiday'] > 1 && $multiplier > 1) ? sprintf(self::$locale['PSP_032'], $this->settings['ps_holiday']) : ''
        ];

    	$info['item'] = [
    		'UserPont'  => [
    		    'locale' => self::$locale['PSP_003'],
    		    'data'   => number_format($this->points['point_point'])
    		],
    		'UserHely'  => [
    		    'locale' => self::$locale['PSP_004'],
    		    'data'   => number_format(self::PointPlace($this->points['point_user']))
    		],
    		'increase'  => sprintf(self::$locale['PSP_005'], showdate("%Y.%m.%d - %H:%M", $this->points['point_increase'])),
    		'udate'     => [
    		    'locale' => self::$locale['PSP_006'],
    		    'data'   => showdate("%d-%H:%M", $message['log_date']),
            ],
            'upont'     => [
    		    'locale' => self::$locale['PSP_007'],
    		    'data'   => "<abbr title='".$message['log_descript']."' class='initialism'>".number_format($message['log_point'])."</abbr>\n",
            ],
    		'umod'      => [
    		    'locale' => self::$locale['PSP_008'],
    		    'data'   => "<span style='color:".($message['log_pmod'] == 1 ? '#5CB85C' : '#FF0000')."'><i class='".($message['log_pmod'] == 1 ? "fa fa-plus-square" : "fa fa-minus-square")."'></i></span>\n",
            ],
            'listmenu'  => self::pointListMenu(),
    	];

        pointPanelItem($info);
    }

}
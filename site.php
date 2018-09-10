<?php
/**
 */
defined('IN_IA') or exit('Access Denied');
define('XUAN_MIXLOAN_DEBUG', false);
!defined('XUAN_MIXLOAN_PATH') && define('XUAN_MIXLOAN_PATH', IA_ROOT . '/addons/xuan_mixloan/');
!defined('XUAN_MIXLOAN_INC') && define('XUAN_MIXLOAN_INC', XUAN_MIXLOAN_PATH . 'inc/');
!defined('MODULE_NAME') && define('MODULE_NAME','xuan_mixloan');
!defined('STYLE_PATH') && define('STYLE_PATH', '../addons/'.MODULE_NAME.'/template/style/');
!defined('NEW_PATH') && define('NEW_PATH', STYLE_PATH.'new/');
!defined('CSS_PATH') && define('CSS_PATH', STYLE_PATH.'css/');
!defined('JS_PATH') && define('JS_PATH', STYLE_PATH.'js/');
!defined('IMG_PATH') && define('IMG_PATH', STYLE_PATH.'images/');
!defined('PIC_PATH') && define('PIC_PATH', STYLE_PATH.'picture/');
require_once XUAN_MIXLOAN_INC.'functions.php'; 
class Xuan_mixloanModuleSite extends WeModuleSite {
	public function __construct(){
		$condition =  array(
			strexists($_SERVER['REQUEST_URI'], '/app/'),
			!strexists($_SERVER['REQUEST_URI'], 'allProduct'),
			!strexists($_SERVER['REQUEST_URI'], 'apply'),
			!strexists($_SERVER['REQUEST_URI'], 'queue'),
			!strexists($_SERVER['REQUEST_URI'], 'login'),
			!strexists($_SERVER['REQUEST_URI'], 'wechat_app'),
			!strexists($_SERVER['REQUEST_URI'], 'find_pass'),
			!strexists($_SERVER['REQUEST_URI'], 'getCode'),
			!strexists($_SERVER['REQUEST_URI'], 'temp'),
            !strexists($_SERVER['REQUEST_URI'], 'notify_url'),
		);
		foreach ($condition as $value) {
			if ($value == false) {
				$con = false;
				break;
			} else {
                if (strexists($_SERVER['REQUEST_URI'], 'register') && !is_weixin()) {
                    $con = false;
                } else {
                    $con = true;
                }
			}
		}
		if ($con) {
			m('member')->checkMember();
		}
	}
	//付款结果返回
	public function payResult($params){
		global $_W, $_GPC;
		$uniacid=$_W['uniacid'];
		$fee = $params['fee'];
		$config = $this -> module['config'];
		if ($params['result'] == 'success') {
            if ($params['from']=='notify') {
                $openid = pdo_fetchcolumn('select openid from '.tablename('core_paylog').'
					where tid=:tid', array(':tid'=>$params['tid']));
                $member = m('member')->getMember($openid);
            } else {
                $openid = m('user')->getOpenid();
                $member = m('member')->getMember($openid);
            }
            if (empty($member['id'])) {
                message('请不要重复提交', $this->createMobileUrl('user'), 'error');
            }
            $type = substr($params['tid'],0,5);
            if ($type=='10001') {
                //购买会员付费
                if (empty($member['id'])) {
                    header("location:{$this->createMobileUrl('user')}");
                }
                $agent = m('member')->checkAgent($member['id']);;
                if ($agent['code'] == 1) {
                    message("您已经是会员，请不要重复提交", $this->createMobileUrl('user'), "error");
                }
                if (floatval($fee) == floatval($config['buy_init_vip_price'])) {
                    $effecttime = time()+86400*$config['buy_init_vip_days'];
                } else {
                    $effecttime = time()+86400*$config['buy_mid_vip_days'];
                }
                pdo_update("xuan_mixloan_member", array('level'=>$_COOKIE['level']), array('id'=>$member['id']));
                $insert = array(
                    "uniacid"=>$_W["uniacid"],
                    "uid"=>$member['id'],
                    "createtime"=>time(),
                    "tid"=>$params['tid'],
                    "fee"=>$fee,
                    'effecttime'=>$effecttime,
                );
                pdo_insert("xuan_mixloan_payment", $insert);
                //模板消息提醒
                $datam = array(
                    "first" => array(
                        "value" => "您好，您已购买成功",
                        "color" => "#173177"
                    ) ,
                    "name" => array(
                        "value" => "{$config['title']}代理会员",
                        "color" => "#173177"
                    ) ,
                    "remark" => array(
                        "value" => '点击查看详情',
                        "color" => "#4a5077"
                    ) ,
                );
                $url = $_W['siteroot'] . 'app/' .$this->createMobileUrl('vip', array('op'=>'salary'));
                $account = WeAccount::create($_W['acid']);
                $account->sendTplNotice($openid, $config['tpl_notice2'], $datam, $url);
                //一级
                $inviter = m('member')->getInviter($member['phone'], $member['openid']);
                if ($inviter) {
                    $agent = m('member')->checkAgent($inviter);
                    if ($agent['code'] == 1) {
                        $re_bonus = $config['inviter_fee_one'] * $fee * 0.01;
                    }
                    if ($re_bonus) {
                        $insert_i = array(
                            'uniacid' => $_W['uniacid'],
                            'uid' => $member['id'],
                            'phone' => $member['phone'],
                            'certno' => $member['certno'],
                            'realname' => $member['realname'],
                            'inviter' => $inviter,
                            'extra_bonus'=>0,
                            'done_bonus'=>0,
                            're_bonus'=>$re_bonus,
                            'status'=>2,
                            'createtime'=>time(),
                            'degree'=>1,
                        );
                        pdo_insert('xuan_mixloan_product_apply', $insert_i);
                        $one_insert_id = pdo_insertid();
                    }
                    //模板消息提醒
                    $one_openid = m('user')->getOpenid($inviter);
                    $datam = array(
                        "first" => array(
                            "value" => "您好，您的徒弟{$member['nickname']}成功购买了代理会员，奖励您推广佣金，继续推荐代理，即可获得更多佣金奖励",
                            "color" => "#173177"
                        ) ,
                        "order" => array(
                            "value" => $params['tid'],
                            "color" => "#173177"
                        ) ,
                        "money" => array(
                            "value" => $re_bonus,
                            "color" => "#173177"
                        ) ,
                        "remark" => array(
                            "value" => '点击查看详情',
                            "color" => "#4a5077"
                        ) ,
                    );
                    $account = WeAccount::create($_W['acid']);
                    $account->sendTplNotice($one_openid, $config['tpl_notice5'], $datam, $url);
                    //二级
                    $man_one = m('member')->getInviterInfo($inviter);
                    $inviter_two = m('member')->getInviter($man_one['phone'], $man_one['openid']);
                    if ($inviter_two) {
                        $agent = m('member')->checkAgent($inviter_two);
                        if ($agent['code'] == 1) {
                            $re_bonus = $config['inviter_fee_two'] * $fee * 0.01;
                        }
                        if ($re_bonus) {
                            $insert_i = array(
                                'uniacid' => $_W['uniacid'],
                                'uid' => $member['id'],
                                'phone' => $member['phone'],
                                'certno' => $member['certno'],
                                'realname' => $member['realname'],
                                'inviter' => $inviter_two,
                                'extra_bonus'=>0,
                                'done_bonus'=>0,
                                're_bonus'=>$re_bonus,
                                'status'=>2,
                                'createtime'=>time(),
                                'degree'=>2,
                            );
                            pdo_insert('xuan_mixloan_product_apply', $insert_i);
                        }
                        //模板消息提醒
                        $two_openid = m('user')->getOpenid($inviter_two);
                        $datam = array(
                            "first" => array(
                                "value" => "您好，您的徒弟{$man_one['nickname']}邀请了{$member['nickname']}成功购买了代理会员，奖励您推广佣金，继续推荐代理，即可获得更多佣金奖励",
                                "color" => "#173177"
                            ) ,
                            "order" => array(
                                "value" => $params['tid'],
                                "color" => "#173177"
                            ) ,
                            "money" => array(
                                "value" => $re_bonus,
                                "color" => "#173177"
                            ) ,
                            "remark" => array(
                                "value" => '点击查看详情',
                                "color" => "#4a5077"
                            ) ,
                        );
                        $account = WeAccount::create($_W['acid']);
                        $account->sendTplNotice($two_openid, $config['tpl_notice5'], $datam, $url);
                    }
                }
                message("支付成功", $this->createMobileUrl('user'), "success");
            }
		}
		if (empty($params['result']) || $params['result'] != 'success') {
			//此处会处理一些支付失败的业务代码
			message("出错啦", $this->createMobileUrl('user'), "error");
		}
	}
}
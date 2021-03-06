<?php
namespace app\wxsite\controller;

use think\Controller;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;
use Flc\Alidayu\Client;
use Flc\Alidayu\App;
use Flc\Alidayu\Requests\AlibabaAliqinFcSmsNumSend;
use app\apk\controller\IndexController;
use app\common\model\User;
use app\common\model\Order;
use \think\Db;
use think\Session;

class PublicController extends BaseController
{
    public function _initialize(){
        if (request()->isOptions()){
            abort(json(true,200));
        }

    }




    public function getCode()
    {
        if ($_GET['code']){
            echo $_GET['code'];die;
        }
        $url = \app\common\tool\WecahtOfficialAccount::getCode();

        header("location: $url");
    }

    public function getCode2()
    {
        var_dump($this->request->param());
        $codeResult = $this->request->param();
        $code = $codeResult['code'];
         if(!$code)   return json([ 'msg' => 'code不能为空', 'code' => -1]);
        $opendIdResult = \app\common\tool\WecahtOfficialAccount::getOpenid($code);


        $userinfo = \app\common\tool\WecahtOfficialAccount::getUserInfo($opendIdResult->access_token, $opendIdResult->openid);

        $oauth_wx = model('User')->where('openid',$userinfo->openid)->find();
        if($oauth_wx){
            model('User')->where('user_id',$oauth_wx['user_id'])->update(['lastip' => request()->ip]);

//                $data['token'] = $this->get_user_token($oauth_wx['user_id']);
            $data['token'] = $this->createToken($oauth_wx['user_id']);
//            $data['user'] = model('User')->with('contact,avater,wx')->find($oauth_wx['user_id']);
            return json(['data' => $data, 'msg' => '登录成功！', 'code' => 1]);
        }else{
//            $avater_id = $this->getavater($userinfo["headimgurl"]);
//            $user = model('User')->create([
//                'avater_id' => $avater_id,
//                'username' => $userinfo['nickname'],
//            ]);
            $oauth_wx = model('User')->create([
                'openid' => $userinfo->openid,
                'nickname' => $userinfo->nickname,
                'sex' => $userinfo->sex,
//                'city' => $userinfo->city,
//                'country' => $userinfo->country,
//                'province' => $userinfo->province,
//                'language' => $userinfo->language,
                'head_img' => $userinfo->headimgurl,
//                'subscribe_time' => date("Y-m-d h:i:s"),
                 'ctime'=>time()
            ]);

//            model("Analysis")->add(0, 0, 1, 0); //统计

//                $data['token'] = $this->get_user_token($user->id);
            $data['token'] = $this->createToken($oauth_wx->user_id);
            $oauth_wx->token = $data['token'];
            $oauth_wx->save();
//            $data['user_id'] = model('User')->with('contact,avater,wx')->find($user->id);
//                return json(['data' => $data,'msg' => '登录成功！', 'code' => 1]);
            $this->_return(1,'登录成功',$data);
        }

//        var_dump($userinfo);
    }


    /**
     * 获取getTicket
     */
    protected function getTicket(){
        $appid = config('wx_appid');
        $appsecret =  config('wx_appsecret');//$wxpay['wxappsecret'];

        $get_token_url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$appid .'&secret='.$appsecret;
        $ACCESS_TOKEN = $this->http_get($get_token_url);
        $data = json_decode($ACCESS_TOKEN,true);
        //$this->ajaxReturn($data['access_token']);
        $get_access = 'https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token='.$data['access_token'].'&type=jsapi';
        $result =  $this->http_get($get_access);
        if ($result) {
            $json = json_decode($result, true);
            if ($json || !empty($json['errcode'])) {
                $this->_return(1,"获取成功！",$json);
            }
            $this->_return(-1,"获取失败，请重试！");
        }
        $this->_return(-1,"获取失败，请重试！");
    }

    /**
     * 用户微信code登入
     */

    public function loging()
    {
        //用code拿openid
        $user_id = $this->request->post('user_id');
        if(!$user_id)
        {
            $code = $this->request->param('code');
            if(!$code) {
                $this->_return(0, '请提交微信code');
            }

            //取用户OPENid
            $result = \app\common\tool\WecahtOfficialAccount::getOpenid($code);

            if(!empty($result->errcode)) {
                $this->_return(0, 'code无效');
            }

            $openid = $result->openid;
            $user = User::get(['openid' => $openid]);

            if(!$user) {
                //新用户添加到数据库
                $user_id = Db('user')->insertGetId(['openid' => $openid,'ctime'=>time()]);
                $user = User::get(['user_id' => $user_id]);
                //注册赠送优惠券
               $discount_money= db('set')->where('id',1)->value('discount_money');
               if($discount_money){
                   $data1['user_id']=$user_id;
                   $data1['coupon_money']=$discount_money;
                   $data1['ctime']=time();
                   db('coupon_log')->insert($data1);
               }
            }
//            write_log('usertoken',"用户信息1111: ".$user);
            //用户微信用户资料
            if(empty($user->nickname) || empty($user->head_img)) {
                $userinfo = \app\common\tool\WecahtOfficialAccount::getUserInfo($result->access_token, $result->openid);
                trace($userinfo);
                trace($user);
                $data['token'] = $this->createToken($user->user_id);
                if(empty($userinfo->errcode)) {
                    $user->nickname = $user->nickname ? $user->nickname : $userinfo->nickname;
                    $user->head_img = $user->head_img ? $user->head_img : $userinfo->headimgurl;
                    $user->sex = $user->sex ? $user->sex : $userinfo->sex;
                    $user->token = $data['token'];
                    $user->save();
                }
            }else{
                $user->token = $this->createToken($user->user_id);
                $user->save();
            }


//            $user_id = $user->user_id;
        }

        //查用户参加活动
        $data = [];
//        $data['token'] = static::createToken('user'.$user_id);
        //$data['token'] = static::createToken('user1');
          $data['token']=$user->token;
          $data['user_id']=$user->user_id;
        $this->_return(1, 'ok',$data);

    }



    public function api()
    {
        $api_name = input('api_name');
        if (!request()->isPost()) {
            $info['code'] = 100;
            $info['msg'] = '请求类型不正确，请用post方式提交';
            $info['data'] = (object)[];
            return json($info);
        }
        if ($api_name) {
            switch ($api_name) {
                case 'getCode':
                    $this->getCode();//获取小程序code
                    break;
                case 'login':
                    $this->login();//小程序授权登录
                    break;
                case 'loging':
                    $this->loging();//登陆
                    break;
                case 'oauth':
                    $this->oauth();//微信授权登录
                    break;
                case 'staff_login':
                    $this->staff_login();//补货员登录
                    break;
                case 'merchant_login':
                    $this->merchant_login();//商家登录
                    break;
                case 'getTicket':
                    $this->getTicket();//获取Ticket
                    break;
                case 'savePass':
                    $this->savePass();//修改密码
                    break;
                case 'bannerlist':
                    $this->bannerlist();
                    break;
                case 'deviceError':
                    $this->deviceError();
                    break;
                case 'resetDevice':
                    $this->resetDevice();
                    break;

                default:
                    $info['code'] = 404;
                    $info['msg'] = '接口不存在';
                    return $info;
                    break;
            }
        }else{
           
//            $info['code'] = 404;
//            $info['msg'] = '接口不能为空';
//            $info['data'] = $api_name;
//            return $info;
            $this->_return(404,'接口名不能为空',$api_name);
        }
    }

    /*
     * 获取设备广告视频
     */
    public function bannerlist(){
       $macno= input('macno');
       if(empty($macno)){
           $this->_return(-1,"设备号不能为空！");
       }
       $shop_id=db('device')->where('macno',$macno)->value('shop_id');
       if($shop_id > 0){
           $where['shop_id']=$shop_id;
           $where['type']=1;
         $list=  db('banner')->where($where)->field('banner_id,image')->order('ctime desc')->select();
         if($list){
             foreach ($list as $k=>$v){
                $list[$k]['image']='http://'.$_SERVER['HTTP_HOST'].$v['image'];
             }
         }
         $this->_return(1,"获取成功",$list);

       }
    }
    /*
     * 设备对比错误
     */
//    public function deviceError(){
//        $macno= input('macno');
//        $rfid= input('rfid');
//
//        write_log('deviceError','传过来的rfid----'.$rfid);
//        write_log('deviceError','传过来的macno----'.$macno);
//        $find= db('device')->where('macno',$macno)->field('device_id,shop_id')->find();
//        if($find['device_id']){
//            write_log('deviceError','1111');
//            $where['device_id']=$find['device_id'];
//            $where['inventory']=['>',0];
//            $arrRfid=   db('device_goods')->where($where)->column('rfid');//查找柜子里存在的rfid
//
//           $azarr= explode(',',$rfid);//apk上传的rfidchua
//
//
////            $array2=explode(',',$rfid);
//            if(!empty($arrRfid)){
//                write_log('deviceError','222');
//                //查询处理的rfid进行处理是其变成数组后和传过来的rfid比较
////                $strRfid= implode(',',$arrRfid);
//
////                $strRfid=trim($strRfid,',');
////                $array1= explode(',',$strRfid);
//                $strRfid= trim(implode(',',$arrRfid),',');
//                $strRfid=str_replace(',,',',',$strRfid);
//                write_log('deviceError','库存过来的rfid----'.$strRfid);
////                print_r($strRfid);
//               $num= strcasecmp($strRfid,$rfid);
//
//                if(!empty($r)){
//                    $data['device_id']=$find['device_id'];
//                    $data['ctime']=time();
//                    $data['macno']=$macno;
//                    $data['shop_id']=$find['shop_id'];
//                    $data['rfid']=implode(',',$r);
//                    db('device_error')->insert($data);
//                    $sql= db('device')->where('macno',$macno)->update(array('status'=>1,'reason'=>'Rfid异常'));
//                }
//
//            }
//            write_log('deviceError','5555');
//            $this->_return(1,"success");
//        }else{
//            write_log('deviceError','6666');
//            $this->_return(-1,"设备不存在");
//        }
//    }
    public function deviceError(){
        $macno= input('macno');
        $rfid= input('rfid');
        $rfid= str_replace(',45465456','',$rfid);
        write_log('deviceError','传过来的rfid----'.$rfid);
        write_log('deviceError','传过来的macno----'.$macno);
        $find= db('device')->where('macno',$macno)->field('device_id,shop_id')->find();
        if($find['device_id']){
            write_log('deviceError','1111');
            $where['device_id']=$find['device_id'];
            $where['inventory']=['>',0];
            $arrRfid=   db('device_goods')->where($where)->column('rfid');//查找柜子里存在的rfid
            $strRfid= trim(implode(',',$arrRfid),',');//把rfid 转成字符串
            $newrfid= explode(',',$strRfid);//在转数组
            $azarr= explode(',',$rfid);//apk上传的rfid转数组
            $count1= count($azarr);//取上传过来的rfid的长度
            $count2= count($newrfid);//取数据里rfid 的长度
            if($count1!=$count2){//比较长度不一样的就直接修改
                write_log('deviceError','5555');
                 $str= $this->diffStr($strRfid,$rfid,2);
                $data['device_id']=$find['device_id'];
                $data['ctime']=time();
                $data['macno']=$macno;
                $data['shop_id']=$find['shop_id'];
                $data['rfid']=$str;
                db('device_error')->insert($data);
                $sql= db('device')->where('macno',$macno)->update(array('status'=>1,'reason'=>'Rfid异常'));
                $this->_return(1,"success");

            }else{
                //一样的长度就一数据库的为标准查找差集
                write_log('deviceError','5555');
                $datarr= array_diff($newrfid,$azarr);
                if(!empty($datarr)){
                    $data['device_id']=$find['device_id'];
                    $data['ctime']=time();
                    $data['macno']=$macno;
                    $data['shop_id']=$find['shop_id'];
                    $data['rfid']=implode(',',$datarr);
                    db('device_error')->insert($data);
                    $sql= db('device')->where('macno',$macno)->update(array('status'=>1,'reason'=>'Rfid异常'));
                }

                $this->_return(1,"success");
            }

        }else{
            write_log('deviceError','6666');
            $this->_return(-1,"设备不存在");
        }
    }



    public  function diffStr($str1,$str2,$type=1){
    $sArr1 = explode(',',$str1);
    $sArr2 =  explode(',',$str2);
    $num1  = count($sArr1);
    $num2  = count($sArr2);
        if($num1 > $num2){
            foreach($sArr1 as $k=>$val){
                if($num2 > $k && $val != $sArr2[$k]){
                    $aNew[] =$val;
                    $aNew[] =$sArr2[$k];
                }elseif($num2 <= $k){
                    $aNew[] = $val;
                }
            }
        }else if($num1 < $num2){
            foreach($sArr2 as $k=>$val){
                if($num1 > $k && $val != $sArr1[$k]){
                    $aNew[] =$sArr1[$k] ;
                    $aNew[] =$val;
                }elseif($num1 <= $k){
                    $aNew[] =$val;
                }
            }
        }elseif($num1 == $num2){
            foreach($sArr1 as $k=>$val){
                if($val != $sArr2[$k]){
                    $aNew[] = $val;
                    $aNew[] = $sArr2[$k];
                }
            }
        }

        if($type==1){
            return $aNew;
        }else{
            return implode(',',$aNew);
        }


}
    /*
     * 设备重置状态
     */
    public function  resetDevice(){
        $macno= input('macno');
        db('device')->where('macno',$macno)->update(array('doorstatus'=>0,'open_status'=>3,'status'=>0));
    }




        // MISS路由
    public function miss()
    {
    	echo '您迷路了哦!';
    }




    /**
     * 获取小程序code
     * @return [type] [description]
     */
    /*public function getCode()
    {
        $code = input('code');
        $weObj = model("Wxpay")->getWeObj(2);
        $token = $weObj->getxOauthAccessToken($code);
        if (!$token) {
            echo json_encode(['data'=>(object)[],'msg'=>$weObj->errMsg,'code'=>-1]);die;
        }
        $this->_return(1,'code获取成功',$token);
    }*/
    /**
     *小程序授权登录
     */
    public function login(){
        $headimgurl = input('headimgurl');
        $nickName   = input('nickName');
        $sex        = input('sex');
        $code = input('code');
        if(empty($code)) $this->_return(-1,'code不能为空',(object)array());
        if(empty($headimgurl)) $this->_return(-1,'用户头像不能为空',(object)array());
        if(empty($nickName)) $this->_return(-1,'用户昵称不能为空',(object)array());
        if(empty($sex)) $this->_return(-1,'用户性别不能为空',(object)array());

        $weObj = model("Wxpay")->getWeObj(2);
        $token = $weObj->getxOauthAccessToken($code);
        if (!$token) {
            echo json_encode(['data'=>(object)[],'msg'=>$weObj->errMsg,'code'=>-1]);die;
        }
        // $this->_return(1,'code获取成功',$token);
        $userInfo['openid']     = $token['openid'];
        $userInfo['headimgurl'] = $headimgurl;
        $userInfo['nickName']   = $nickName;
        $userInfo['sex']        = $sex;
        $res = $this->weixin_login($userInfo);
        echo json_encode($res);
    }

    //商家登录
    public function merchant_login(){
//        $mobile = input('mobile');
//        $pass = input('pass');
//        if(!$mobile || !$pass)$this->_return(-1,'缺少参数！');
////        if(!$this->check_mobile($mobile))$this->_return(-1,'手机号码有误');
//        $user = Db::name('admin')->where(['username'=>$mobile,'password'=>md5($pass),'type'=>2])->find();
//        if(!$user)$this->_return(-1,'账号或密码错误！');
//        $data['data'] = Db::name('admin')->alias('a')
//            ->join('shop b','a.id = b.aid','left')
//            ->where(['a.username'=>$mobile,'a.type'=>2])
//            ->field('b.shop_id')
//            ->find();
//        $this->_return(1,'ok！',$data);
        if (!$_POST['mobile'] || !$_POST['pass']) $this->_return(-1,'缺少参数');

        if (!Db::name('shop')->where(['account'=>$_POST['mobile']])->find()) $this->_return(-1,'账号不存在');
        //获取商家信息
        $shopInfo = Db::name('shop')->where(['account'=>$_POST['mobile'],'password'=>md5($_POST['pass'])])
            ->field('shop_id')->find();
        if (!$shopInfo) $this->_return(-1,'密码错误');

        //今日开始时间
        $begintime = strtotime(date('Y-m-d',time()).' 00:00:00');
        //今日结束时间
        $endtime = strtotime(date('Y-m-d',time()).' 23:59:59');

        $orderMap = array(
            'status'    =>  3,
            'shop_id'   =>  $shopInfo['shop_id'],
            'pay_time'  =>  ['between',[$begintime,$endtime]],
        );
        $todayEarnings = Db::name('order')->where($orderMap)->sum('pay_price')? : '0.00';
        $todayOrder    = Db::name('order')->where($orderMap)->count() ? : 0;
        $openCondition = array(
            'shop_id'   =>  $shopInfo['shop_id'],
            'status'      =>  3,
            'close_time'     =>  ['between',[$begintime,$endtime]]
        );
        $todayOpenTotal = Db::name('device_order')->where($openCondition)->count();

        $token= $this->createToken($shopInfo['shop_id']);
        Db::name('shop')->where(['shop_id'=>$shopInfo['shop_id']])->update(['token'=>$token]);
        $result['shop_id'] = $shopInfo['shop_id'];
        $result['todayEarnings'] = $todayEarnings; //今日收益
        $result['todayOrder']    = $todayOrder; //今日订单总数
        $result['todayOpen']     = $todayOrder+$todayOpenTotal; //今日开门次数
        $result['token']     =$token; //今日开门次数
        $this->_return(1,'获取成功',$result);
    }

    //员工登录
    public function staff_login(){
        $mobile = input('mobile');
        $pass = input('pass');
        if(!$mobile || !$pass)$this->_return(-1,'缺少参数！');
        $user = Db::name('user')->where(['mobile'=>$mobile,'userpass'=>md5($pass),'type'=>2])->find();
        if($user['type'] == 1)$this->_return(-1,'你还不是补货员！');
        if(!$user)$this->_return(0,'账号或密码错误！');
        $device_count = Db::name('device')->where(['shop_id'=>$user['shop_id']])->count();
//        $token= $this->get_user_token($user['user_id']);
        $token= $this->createToken($user['user_id']);
        Db::name('user')->where('user_id',$user['user_id'])->update(['token'=>$token]);
        $data['device_count'] = $device_count;
        $data['staff_user_id'] = $user['user_id'];
        $data['shop_id'] = $user['shop_id'];
        $data['token'] =$token;
        $this->_return(1,'ok！',$data);

    }

    //用户注册
    public function register(){
        $phone = input('param.phone');
        $username = input('param.username');
        $password = input('param.password');
        
        $uuid = input('param.captcha_uuid');
        $code = input('param.captcha');

        $verify = model('SmsVerify')->where('uuid',$uuid)->find();
        if($verify['phone'] != $phone){
            return json(['data' => false, 'msg' => '手机号不正确', 'code' => 0]);
        }
        if($verify['code'] != $code){
            return json(['data' => false, 'msg' => '验证码不正确', 'code' => 0]);
        }

        $result = model('User')->validate(true)->save([
                'phone' =>  $phone,
                'username' =>  $username,
                'password'  =>  md5($password)
            ]);
        if(false === $result){
            // 验证失败 输出错误信息
            $msg = model('User')->getError();
            return json(['data' => false, 'msg' => $msg, 'code' => 0]);
        }
        model("Analysis")->add(0, 0, 1, 0); //统计
        return json(['data' => false, 'msg' => '注册成功', 'code' => 1]);
    }
    //修改密码
    public function savePass(){
        $user_id=input('user_id')?:$this->_return(-1,'用户id不能为空 ',(object)array());

        $usedpass=input('usedpass')?:$this->_return(-1,'旧密码不能为空 ',(object)array());
        $newpass=input('newpass')?:$this->_return(-1,'新密码不能为空 ',(object)array());
        $repeatpass=input('repeatpass')?:$this->_return(-1,'重复新密码不能为空 ',(object)array());
        $find= db('user')->where('user_id',$user_id)->field('userpass')->find();
        if($find['userpass']!=md5($usedpass)){
            $this->_return(-1,'旧密码错误',$usedpass);
        }
        if($newpass!=$repeatpass){
            $this->_return(-1,'两次密码输入不一致');
        }
        db('user')->where('user_id',$user_id)->update(array('userpass'=>md5($newpass)));
        $this->_return(1,'修改密码成功');
    }
    //重置密码
    public function resetPassword(){
        $data = input('param.');

        $verify = model('SmsVerify')->where('uuid',$data['captcha_uuid'])->find();
        if($verify['phone'] != $data['phone']){
            return json(['data' => false, 'msg' => '手机号不正确', 'code' => 0]);
        }
        if($verify['code'] != $data['captcha']){
            return json(['data' => false, 'msg' => '验证码不正确', 'code' => 0]);
        }

        $result = model('User')->where('phone',$data['phone'])->update(['password' => md5($data['password'])]);
        if($result){
            return json(['data' => false, 'msg' => '重置成功', 'code' => 1]);
        }else{
            return json(['data' => false, 'msg' => '重置失败', 'code' => 0]);
        }
    }

    //短信成功返回object(stdClass)#34 (2) {
            //   ["result"] => object(stdClass)#35 (3) {
            //     ["err_code"] => string(1) "0"
            //     ["model"] => string(26) "106059359902^1108216906516"
            //     ["success"] => bool(true)
            //   }
            //   ["request_id"] => string(12) "zqbpj5leacdv"
            // }
    // 错误返回object(stdClass)#34 (5) {
            //   ["code"] => int(15)
            //   ["msg"] => string(20) "Remote service error"
            //   ["sub_code"] => string(26) "isv.BUSINESS_LIMIT_CONTROL"
            //   ["sub_msg"] => string(18) "触发业务流控"
            //   ["request_id"] => string(12) "z28noqlmk1yk"
            // }

    //发送验证码
    public function sendSmsCaptcha(){
        $phone = input('param.phone');

        try {
            $uuid1 = Uuid::uuid4();
            $uuid = $uuid1->toString();
        } catch (UnsatisfiedDependencyException $e) {
            echo 'Caught exception: ' . $e->getMessage() . "\n";
        }
        $code = mt_rand(100000, 999999);

        $sms_tpl = model('SmsTpl')->where('type','register')->find()->toArray();
        $sms_config = model('Sms')->find()->toArray();
        // 配置信息
        $config = [
            'app_key'    => $sms_config['app_key'],
            'app_secret' => $sms_config['app_secret'],
            // 'sandbox'    => true,  // 是否为沙箱环境，默认false
        ];

        $client = new Client(new App($config));
        $req    = new AlibabaAliqinFcSmsNumSend;
        $req->setRecNum($phone)
            ->setSmsParam([
                'code' => $code
            ])
            ->setSmsFreeSignName($sms_config['sign'])
            ->setSmsTemplateCode($sms_tpl['template_code']);

        $resp = $client->execute($req);
        $result = isset($resp->result->success) ? $resp->result->success : false;
        if($result){
            model('SmsVerify')->create([
                'uuid'  =>  $uuid,
                'phone' =>  $phone,
                'code'  =>  $code
            ]);
            $data['uuid'] = $uuid;
            return json(['data' => $data, 'msg' => '发送成功', 'code' => 1]);
        }else{
            $msg = isset($resp->sub_msg) ? $resp->sub_msg : '请输入手机号';
            return json(['data' => false, 'msg' => $msg, 'code' => 0]);
        }
    }

  //插件调试模式
    public function oauthDebug()
    {
        $config = model('app\common\model\Config')->find();

        if($config['debug']){
            session("userId", 1);//插件兼容
        } 
    }
    //插件自动登陆
    public function oauthLogin()
    {
      //  $this->oauthDebug();//调试模式
        if (!session("userId")) {
            $weObj = model("app\common\model\WxConfig")->getWeObj();
            $token = $weObj->getOauthAccessToken();
            $wxConfig = model("app\common\model\WxConfig")->find()->toArray();
            if (!$token) {
                $url = $weObj->getOauthRedirect('http://'.$_SERVER['HTTP_HOST'].'/yzshg/public/oauthLogin.html');
                // $url = 'http://weixin.wemallshop.com/oauth-proxy.html?appid='.$wxConfig["appid"].'&scope=snsapi_userinfo&state=&redirect_uri='.$redirect;
                header("location: $url");
                die();
            }else{
                $userInfo = $weObj->getOauthUserinfo($token["access_token"], $token["openid"]);
    
                $oauth_wx = model('app\common\model\OauthWx')->where('openid',$userInfo["openid"])->find();
                if($oauth_wx){
                    model('app\common\model\User')->where('id',$oauth_wx['user_id'])->update(['last_login_ip' => request()->ip]);
                    
                    session("userId", $oauth_wx['user_id']);
                }else{
                    $avater_id = $this->getavater($userInfo["headimgurl"]);
                    $user = model('app\common\model\User')->create([
                        'avater_id' => $avater_id,
                        'username' => $userInfo['nickname'],
                    ]);
                    $oauth_wx = model('app\common\model\OauthWx')->create([
                        'user_id' => $user->id,
                        'openid' => $token["openid"],
                        'nickname' => $userInfo['nickname'],
                        'sex' => $userInfo['sex'],
                        'city' => $userInfo['city'],
                        'country' => $userInfo['country'],
                        'province' => $userInfo['province'],
                        'language' => $userInfo['language'],
                        'headimgurl' => $userInfo['headimgurl'],
                        'subscribe_time' => date("Y-m-d h:i:s"),
                        'subscribe' => 1,
                    ]);
                   
                  //  model("app\common\model\Analysis")->add(0, 0, 1, 0); //统计
                    session("userId", $user->id);
                } 
            }
        }

        $user = model('app\common\model\User')->with('contact,avater,wx')->find(session("userId"));
        return $user->toArray();
    }


    public function wxnotify()
    {


        \app\common\tool\WecahtOfficialAccount::notify('\app\common\model\Order');
    }

    public function wxnotify1()
    {


        \app\common\tool\WecahtOfficialAccount::notify('\app\dlc\model\Recharge');
    }
    public function setDevice()
    {
       $macno= input('macno');
      $find= db('device')->where('macno',$macno)->find();
      if(empty($find)){
          $this->_return(-1,'设备不存在',array());
      }
     $res= db('device')->where('macno',$macno)->update(['status'=>1]);
        $this->_return(1,'成功',array());

    }

    //公众号授权登录
    public function  oauth(){
//        $this->debug();//调试模式
        $redirect = input('param.code');
        if(empty($redirect)){
            $this->_return(-1,'code不能为空');
        }
        $weObj = model("WxConfig")->getWeObj();
        $token = $weObj->getOauthAccessToken();
//        print_r($token);
//        $wxConfig = model("WxConfig")->find()->toArray();
        if (!$token) {
            $url = $weObj->getOauthRedirect($redirect);
            // $url = 'http://weixin.wemallshop.com/oauth-proxy.html?appid='.$wxConfig["appid"].'&scope=snsapi_userinfo&state=&redirect_uri='.$redirect;
            $data['url'] = $url;
//            return json(['data' => $data, 'msg' => '授权url', 'code' => 0]);
            $this->_return(0,'授权url',$data);
        }else{

            $userInfo = $weObj->getOauthUserinfo($token["access_token"], $token["openid"]);

            $oauth_wx = model('OauthWx')->where('openid',$userInfo["openid"])->find();
            if($oauth_wx){
                model('User')->where('id',$oauth_wx['user_id'])->update(['last_login_ip' => request()->ip]);

//                $data['token'] = $this->get_user_token($oauth_wx['user_id']);
                $data['token'] = $this->createToken($oauth_wx['user_id']);
                $data['user'] = model('User')->with('contact,avater,wx')->find($oauth_wx['user_id']);
                return json(['data' => $data, 'msg' => '登录成功！', 'code' => 1]);
            }else{
                $avater_id = $this->getavater($userInfo["headimgurl"]);
                $user = model('User')->create([
                    'avater_id' => $avater_id,
                    'username' => $userInfo['nickname'],
                ]);
                $oauth_wx = model('OauthWx')->create([
                    'user_id' => $user->id,
                    'openid' => $token["openid"],
                    'nickname' => $userInfo['nickname'],
                    'sex' => $userInfo['sex'],
                    'city' => $userInfo['city'],
                    'country' => $userInfo['country'],
                    'province' => $userInfo['province'],
                    'language' => $userInfo['language'],
                    'headimgurl' => $userInfo['headimgurl'],
                    'subscribe_time' => date("Y-m-d h:i:s"),
                    'subscribe' => 1,
                ]);

//                model("Analysis")->add(0, 0, 1, 0); //统计

//                $data['token'] = $this->get_user_token($user->id);
                $data['token'] = $this->createToken($user->id);
                $data['user_id'] = model('User')->with('contact,avater,wx')->find($user->id);
//                return json(['data' => $data,'msg' => '登录成功！', 'code' => 1]);
                $this->_return(1,'登录成功',$data);
            }
        }
    }
    public function weblogtime(){
      $datetime=  input('datetime');
      $user_id=  input('user_id')?:0;
        write_log('OpenDoor',"当前user_id--".$user_id."--前端调取接口传过来时间--".date('Y-m-d H:i:s',$datetime).'---服务器是当前时间--'.date('Y-m-d H:i:s',time()));
    }
    public function send(){
        $wecaht = new \app\dlc\controller\WechatController();
        $wecaht->sendTplMsgCoupon();
    }
    public function gettoken()
    {
//       echo BaseController::decodeToken('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOjgwLCJleHAiOjE1MzU3MDc3NzR9.wQtVCFWvu683h4Q-6q5Sk5LisCwN5kjbk3YZF90LXpU');
//die;

        $token = BaseController::createToken(2);
        echo $token;
    }
    
    //小程序授权登录
    public function x_oauth(){
        $data = input('param.');

        $userInfo = input('?param.userInfo') ? $data['userInfo'] : '';
        if(!$userInfo){
            return json(['data' => false, 'msg' => '微信未授权，请退出微信试试！', 'code' => 0]);
        }

        $weObj = model("WxConfig")->getWeObj(2);
        $token = $weObj->getxOauthAccessToken($data['code']);

        $oauth_applet = model('OauthApplet')->where('openid',$token["openid"])->find();
        if($oauth_applet){
            model('User')->where('id',$oauth_applet['user_id'])->update(['last_login_ip' => request()->ip]);

            $data2['token'] = $this->get_user_token($oauth_applet['user_id']);
            $data2['user'] = model('User')->with('contact,avater,applet')->find($oauth_applet['user_id']);
            return json(['data' => $data2, 'msg' => '登录成功！', 'code' => 1]);
        }else{
            $avater_id = $this->getavater($userInfo["avatarUrl"]);
            $user = model('User')->create([
                'avater_id' => $avater_id,
                'username' => $userInfo['nickName'],
            ]);
            $oauth_applet = model('OauthApplet')->create([
                'user_id' => $user->id,
                'openid' => $token["openid"],
                'nickname' => $userInfo['nickName'],
                'gender' => $userInfo['gender'],
                'city' => $userInfo['city'],
                'province' => $userInfo['province'],
                'language' => $userInfo['language'],
                'avatarUrl' => $userInfo['avatarUrl'],
            ]);

            model("Analysis")->add(0, 0, 1, 0); //统计

            $data2['token'] = $this->get_user_token($user->id);
            $data2['user'] = model('User')->with('contact,avater,applet')->find($user->id);
            return json(['data' => $data2,'msg' => '登录成功！', 'code' => 1]);
        }
    }


    //获取微信头像
    // public function getavater($headimgurl){
    //     try {
    //         $uuid1 = Uuid::uuid4();
    //         $uuid = $uuid1->toString();
    //     } catch (UnsatisfiedDependencyException $e) {
    //         echo 'Caught exception: ' . $e->getMessage() . "\n";
    //     }

    //     $savename = $uuid .'.png';
    //     $savepath = 'avatar/';

    //     $filename = ROOT_PATH . 'public' . DS . 'uploads/' . $savepath . $savename;
        
    //     http_down($headimgurl, $filename);

    //     $file = model('app\common\model\File')->create([
    //                 'name' => $savename,
    //                 'ext' => 'png',
    //                 'type' => 'image/jpeg',
    //                 'savename' => $savename,
    //                 'savepath' => $savepath,
    //             ]);
    //     return $file->id;
    // }

//    //获取用户token
//    public function get_user_token($user_id){
//        $token = new \Gamegos\JWT\Token();
//        $token->setClaim('user_id', $user_id); // alternatively you can use $token->setSubject('someone@example.com') method
//        $token->setClaim('exp', time() + config('jwt_time'));
//
//        $encoder = new \Gamegos\JWT\Encoder();
//        $encoder->encode($token, config('jwt_key'), config('jwt_alg'));
//        return $token->getJWT();
//    }
    //调试模式
    public function debug(){
    //    $config = model('Config')->find()->toArray();

        if($config['debug']=1){
            $data['token'] = $this->get_user_token(1);
          //  $data['user'] = model('User')->with('contact,avater')->find(1)->toArray();

            abort(json(['data' => $data, 'msg' => '登录成功！', 'code' => 1]));
        }
    }
    /*
     * 回调地址
     * @param  $post['type'] 1：购买商品 2：补货 3：补货异常 5：清空商品
     * @param  $post['status'] 1 成功 0 失败
     * @param  $post['user_id'] 用户id
     */
    public function get_open_arr(){
        $post= input('post.');
        write_log('get_open_json',"接受数据: ".json_encode($post,true));
        $mqtt = new IndexController();
        $user_id= db('order')->where('order_number',$post['oid'])->value('user_id');
        write_log('get_open_json',"get_open_arr----user_id--one--=: "."sdxddshg_".$user_id);
        if(!$user_id){
            $user_id=  db('device_order')->where('order_number',$post['oid'])->value('staff_id');
            write_log('get_open_json',"get_open_arr----user_id--two--=: "."sdxddshg_".$user_id);
        }

        //开门状态返回
        if($post['door_type']==1){
            $macno= str_replace('com.dlc.thinkingvalley_','',$post['macno']);
            switch ($post['type']){
                case 1:
                    if($post['status']==1){
                        $data['doorstatus']=1;
                        $data['open_status']=1;
                        $res= db('device')->where('macno',$macno)->update($data);
                        $res1= db('device_order')->where(['order_number'=>$post['oid']])->update(['open_time'=>time()]);
                        write_log('OpenDoor',"购买商品开门成功");
                        $mqtt->notifyH5Message(1,'购买商品开门成功',"sdxddshg_".$user_id,$post['oid']);
                        write_log('get_open_json',"购买商品开门成功通知前端websocket的udid-----: "."sdxddshg_".$user_id.'--设备号----'.$macno.'--------sql----'.$res1);

                    }else{

                        $data['doorstatus']=0;
                        $data['open_status']=2;
                        write_log('OpenDoor',"购买商品开门失败");
                        db('device')->where('macno',$macno)->update($data);
                        $mqtt->notifyH5Message(1,'购买商品开门失败',"sdxddshg_".$user_id,$post['oid']);
                        write_log('get_open_json',"购买商品开门失败通知前端websocket的udid: "."sdxddshg_".$user_id);

                    }
                    break;
                case 2:
                    if($post['status']==1){
                        $data['doorstatus']=1;
                        $data['open_status']=1;
                        $device_id= db('device_order')->where('order_number',$post['oid'])->value('device_id');
                        if($device_id){
                            $res=  db('device')->where('device_id',$device_id)->update(array('doorstatus'=>0,'open_status'=>3));
                        }

//                      $res= db('device')->where(array('macno'=>$macno))->update($data);
                        $mqtts= $mqtt->notifyH5Message(1,'补货开门成功',"sdxddshg_".$user_id,$post['oid']);
                        write_log('get_open_json',"补货开门成功通知前端websocket的udid-----: "."sdxddshg_".$user_id.'--设备号----'.$macno.'----设备id----'.$device_id.'--------sql----'.$res);

                        write_log('retrievalDoor',"补货开门成功通知前端websocket的udid-----: "."sdxddshg_".$user_id.'--设备号----'.$macno.'----设备id----'.$device_id.'--------修改设备状态sql执行结果----'.$res.'----socket---返回数据'.$mqtts);

                    }else{
                        $data['doorstatus']=0;
                        $data['open_status']=2;
                        $res= db('device')->where('macno',$macno)->update($data);
                        write_log('get_open_json',"补货开门失败通知前端websocket的udid: "."sdxddshg_".$user_id);
                        write_log('retrievalDoor',"补货开门失败通知前端websocket的udid: "."sdxddshg_".$user_id.'修改设备状态sql执行结果'.$res);
                        $mqtt->notifyH5Message(1,'补货开门失败',"sdxddshg_".$user_id,$post['oid']);

                    }
                    break;
                case 4:

                    break;
                case 5:
                    $mqtt->notifyH5Message(1,'商品清空开门成功',"sdxddshg_".$post['oid'],$post['oid']);
                    write_log('get_open_json',"商品清空开门通知前端websocket的udid: "."sdxddshg_".$post['oid']);
                    break;
            }
        }

        elseif ($post['door_type']==2){
            $macno= str_replace('com.dlc.thinkingvalley_','',$post['macno']);
            switch ($post['type']){
                case 1:

//                    if($post['status']==1){
////                        db('order')->where(array('user_id'=>$user_id,'num'=>0))->update(array('status'=>-1));
////                        $mqtt->notifyH5(1,'购买商品成功',"sdxddshg_close_".$user_id,array(),$post['oid']);
//                    }else{
//                        write_log('OpenDoor',"购买商品关门失败通知前端websocket的udid: "."sdxddshg_close_".$user_id);
//                        $mqtt->notifyH5(1,'购买商品关门门失败',"sdxddshg_close_".$user_id,array(),$post['oid']);
//                        write_log('get_open_json',"购买商品关门失败通知前端websocket的udid: "."sdxddshg_close_".$user_id);
//
//                    }
                    break;
                case 2:
                    if($post['status']==1){
                        $device_id= db('device_order')->where('order_number',$post['oid'])->value('device_id');
                        if($device_id){
                            $res=  db('device')->where('device_id',$device_id)->update(array('doorstatus'=>0,'open_status'=>3));
                        }

//                     $mqtts=  $mqtt->notifyH5(1,'补货关门成功',"sdxddshg_close_".$user_id,array(),$post['oid']);
                        write_log('get_open_json',"补货关门成功成功通知前端websocket的udid-----: "."sdxddshg_".$user_id.'--设备号----'.$macno.'--------sql----');


                        write_log('retrievalDoor',"补货关门成功成功通知前端websocket的udid-----: "."sdxddshg_".$user_id.'--设备号----'.$macno.'--------修改设备状态sql执行结果----'.$res.'----socket---返回数据');

                    }else{

//                       $mqtt->notifyH5(1,'补货关门失败',"sdxddshg_close_".$user_id,array(),$post['oid']);
                        rite_log('retrievalDoor',"补货关门成功失败通知前端websocket的udid-----: "."sdxddshg_".$user_id.'--设备号----'.$macno);
                        write_log('get_open_json',"补货关门失败通知前端websocket的udid: "."sdxddshg_close_".$user_id);

                    }
                    break;
                case 4:
                    break;
                case 5:
                    $mqtt->notifyH5(1,'商品清空成功',"sdxddshg_close_".$post['oid'],array(),$post['oid']);
                    write_log('get_open_json',"盘点成功通知前POST数据".json_encode($post,true));
                    write_log('get_open_json',"商品清空通知前端websocket的udid: "."sdxddshg_close_".$post['oid']);
                    break;
            }
        }else{
            write_log('get_open_json',"不做处理开门状态返回不对door_type=".$post['door_type'].'------设备开启状态status='.$post['status']);
        }



//       dump($user_id);die;


    }

    public function shareQrcode($device_number,$type='string'){

        if(!$device_number) {
            $device_number=input('post.device_number');
        }
        $text=$device_number;
        vendor("phpqrcode.phpqrcode");
        $errorCorrectionLevel = "H";
        $matrixPointSize = "8";
        ob_clean();//这个一定要加上，清除缓冲区
        if($type == 'string'){
            ob_start();
        }
        $url='http://sdxddshg.app.xiaozhuschool.com/h5/html/opening.html?macno='.$device_number;
        \QRcode::png($url, false, $errorCorrectionLevel, $matrixPointSize, 4, false);
        if($type == 'string'){
            $image = ob_get_contents();
            ob_end_clean();
            if($text){
                $im = imagecreatefromstring($image);
                $width = imagesx($im);
                $height = imagesy($im);
                $newimg = imagecreatetruecolor($width, $height + 20);
//                $newbg = imagecolorallocatealpha($newimg, 0, 0, 0, 127);
//                imagealphablending($newimg, false);
//                imagefill($newimg, 0, 0, $newbg);
                imagefill($newimg, 0, 0, imagecolorallocate($newimg, 255, 255, 255));//白色背景
                imagecopyresampled($newimg, $im, 0, 0, 0, 0, $width, $height, $width, $height);
                $fontfile = './public/texb.ttf';
                $fontinfo = imagettfbbox(18, 0, $fontfile, $text);
                $fontwidth = abs($fontinfo[4] - $fontinfo[0]);
                //$fontheight = abs($fontinfo[5] - $fontinfo[1]);
                imagefttext($newimg, 18, 0, floor(($width - $fontwidth) / 2), $height, imagecolorallocate($newimg, 0, 0, 0), $fontfile, $text);
                imagesavealpha($newimg, true);
                ob_start();
                imagepng($newimg);
                $image = ob_get_contents();
                //ob_end_clean();
            }

            return $image;
        }
    }
    public function download(){
        $ids = rtrim(input('param.ids'))?:die(json_encode(['status' => 0,'msg' => '缺少参数']));
        $QRfiles = db('device') -> where(['device_id' => ['in',$ids]]) -> select();
        $zip = new \ZipArchive();
        $filename = './public/uploads/qr.zip';
        $res = $zip->open($filename, \ZipArchive::OVERWRITE);
        //$this -> ajaxReturn($res);
        if($res === TRUE){
            foreach($QRfiles as $k => $v){
                $zip->addFromString($v['macno'].'.png', $this->shareQrcode($v['macno'],'string'));
            }
            $zip->close();
        }
//        ob_clean();

        $info['status']=1;
        $info['url']= '/public/uploads/qr.zip';
        return $info;


    }
    /**
     * @param $url
     * @return bool|mixed
     */
    public function http_get($url){
        $oCurl = curl_init();
        if (stripos($url, "https://") !== FALSE) {
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
        }
        curl_setopt($oCurl, CURLOPT_URL, $url);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
        $sContent = curl_exec($oCurl);
        $aStatus = curl_getinfo($oCurl);
        curl_close($oCurl);
        if (intval($aStatus["http_code"]) == 200) {
            return $sContent;
        } else {
            return false;
        }
    }

    /*
     * 获取客服电话
     */
    public function get_phone(){
        $tel=db('about')->value('tel');
        $data['tel']=$tel;
        $this->_return(1,'ok',$data);
    }

    public function test1(){
//           $maco='com.dlc.thinkingvalley_833a21350c5413a8';
//
//
//         $m= str_replace('com.dlc.thinkingvalley_','',$maco);
//           dump( $m);
        $find= db('device_order')->where(array('device_id'=>24,'staff_id'=>421,'status'=>['in',[1,2]]))->find();
        var_dump($find);

    }
    public function readlog($name,$date){
        read_log($name,$date);
    }

    /*
          * 日志读取
          * $logname 文件夹日期名称 例：201807
          * $log  日的日志 例：23.log
          */
    public function r($logname,$log){

//        $file = APP_PATH.'logs/'.'/201807/23.log';
//        print_r($logname);
//        print_r($log);
        header("Content-type: text/html; charset=utf-8");
        $name='/'.$logname.'/'.$log;
//        print_r($name);
        $file = 'http://'.$_SERVER['HTTP_HOST'].'/runtime/log/'.$name;
//        print_r($file);
        echo '<pre>';
        echo file_get_contents($file);
        echo '<pre/>';
    }
    public function read_log($name){

//        $file = APP_PATH.'logs/'.'/201807/23.log';
//        print_r($logname);
//        print_r($log);
        header("Content-type: text/html; charset=utf-8");

//        print_r($name);
        $file = 'http://'.$_SERVER['HTTP_HOST'].'/runtime/log/'.$name;
//        print_r($file);
        echo '<pre>';
        echo file_get_contents($file);
        echo '<pre/>';
    }

    public function version(){

       $macno= input('macno');
        $find= db('device')->where('macno',$macno)->find();
        $device_order= db('device_order')->where(array('device_id'=>$find['device_id'],'status'=>array('in',['1','2'])))->select();
        if($device_order){
//            $this->_return(-1,'有员工正在补货，不能发生该指令!!');
            echo '有员工正在补货，不能发生该指令!!';
        }

        $order= db('order')->where(array('device_id'=>$find['device_id'],'status'=>1))->select();
        if($order){
//            $this->_return(-1,'有用户正在购买商品，不能发生该指令！！');
            echo '有用户正在购买商品，不能发生该指令！！';
        }
        if($find['doorstatus'] === 1 && $find['open_status'] === 1) {
//            $this->_return(-1,'设备还未关门，请先关门');
            echo '设备还未关门，请先关门';
        }

        $arr['type']=8;
        $arr['notify_url']='http://sdxddshg.app.xiaozhuschool.com/wxsite/Public/getversion';
        $arr['order_number']='';
        $json_str=json_encode($arr,true);
        $data['macno'] = 'com.dlc.thinkingvalley_99acda74080abbc5';
        $data['macno'] = 'com.dlc.thinkingvalley_'.$macno;
        $data['version'] = 3;
        $data['sign'] = md5('dlcshouhuogui');
        $data['data']=$json_str;
        $res  = httpPost('http://10.27.204.40/shouhuogui/n_operate',$data);
            write_log('version',"更新版本----".$res.'发送时间--'.date('Y-m-d H:i:s',time()));

    }
    public function getversion(){
        $post= input('post.');
       $macno= str_replace('com.dlc.thinkingvalley_','',$post['macno']);
      $sql= db('device')->where('macno',$post['macno'])->update(array('version'=>$post['versionCode']));
        write_log('getversion',"更新版本----".json_encode($post,true));
        write_log('getversion',"更新原设备号----".$post['macno']);
        write_log('getversion',"更新新设备号----".$macno);
        write_log('getversion',"sal语句执行----".$sql);
    }
    public function shopversion(){
        $shop_id =input('shop_id');
        $listmacno= db('device')->where('shop_id',$shop_id)->field('device_id,macno')->select();
        $arr['type']=8;
        $arr['notify_url']='http://sdxddshg.app.xiaozhuschool.com/wxsite/Public/getversion';
        $arr['order_number']='';
        $json_str=json_encode($arr,true);
        foreach ($listmacno as $k=>$v){
            $data['macno'] = 'com.dlc.thinkingvalley_'.$v['macno'];
            $data['version'] = 3;
            $data['sign'] = md5('dlcshouhuogui');
            $data['data']=$json_str;
            $res  = httpPost('http://10.27.204.40/shouhuogui/n_operate',$data);
            $res = json_decode($res,true);
            write_log('version',"商家下的所有设备都更新版本----".$res.'发送时间--'.date('Y-m-d H:i:s',time()));
        }
        echo 'success';
    }

    public function testst()
    {
        $wecaht = new \app\dlc\controller\WechatController();
        $wecaht->sendTplMsgOrder();

    }

    public function wxpay(){
     $wxpay=   new \app\wxsite\controller\WxpaymentController();
     $wxpay->querycontract('oRW6m1AGov9XXOkYA9ZW4yZFIU2M');

    }


    public function testvession()
    {
        $manco='com.dlc.thinkingvalley_99acda74080abbc5';
        $macno= str_replace('com.dlc.thinkingvalley_','',$manco);
        print_r($macno);
    }
    public function paporderquery(){
        $url = 'https://api.mch.weixin.qq.com/pay/paporderquery';
        Vendor("WxPayPubHelper.WxPayPubHelper");
//        $wxConfig = model("WxConfig")->find();
//        $wx_pay = model("payment")->where('type','wxpay')->find();
//        $config = json_decode($wx_pay['config'],true);
        //使用统一支付接口
        $unifiedOrder = new \UnifiedOrder_pub('wx3a9ffa0767b67e9a','926392de5a1ed556143f5de9b2adebcb','1511220331','s4d9q7tjk21hgk9uio79i621daqw98er');

        $obj['appid'] = 'wx3a9ffa0767b67e9a';
        $obj['mch_id'] = '1511220331';
        $obj['plan_id'] = '122069';
        $obj['openid'] = 'oRW6m1O5Ir99IBldYJWd7RXTHc9k';
        $obj['version'] = '1.0';
        $obj['sign'] =  $unifiedOrder->getSign($obj);
        $xml = $unifiedOrder->arrayToXml($obj);
        $result = $unifiedOrder->postXmlCurl($xml,$url);
        $arr = $unifiedOrder->xmlToArray($result);
        print_r($xml);
        print_r($arr);
        die;
        if($arr['return_code'] == 'SUCCESS' && $arr['result_code'] == 'FAIL'){ //未授权免密支付
            return true;
        }elseif($arr['return_code'] == 'SUCCESS' && $arr['contract_state'] == 1){ //已解约
            return true;
        }else{
            return false;
        }
    }
    public function soket(){
//        $mqtt = new IndexController();
//        $re1=$mqtt->notifyH5Message(1,'测试soket开',"sdxddshg_101");
//        $re2=$mqtt->notifyH5(1,'测试soket关',"sdxddshg_close_101",array(),'B201811067942');
////        print_r($re1);
////        print_r($re2);
//
//        $info['code'] = 1;
//        $info['msg'] = '清空成功';
//        $info['order_number'] = '';
//
//        $info['data'] = array();
//        $data['data'] = $info;
//        $data['udid']  = 'sdxddshg_close_101';
//        $new_data['data'] = json_encode($data);
//        print_r($new_data);

        $newlist[] = array(
            'inventory' => 1,
            'title' => 1,
            'img' => 1,
        );
        $newlist['data']['type']=1;
        print_r(json_encode($newlist,true));
    }
    public function closeDoor(){
        $rfid='E28011700000020A2B78F806,E28011700000020A2B78F9EF,E28011700000020A2B778BEF,E28011700000020A2B778BDF,E28011700000020A2B78C456,E28011700000020A2B791A66,E28011700000020A2B77C8B7,E28011700000020A2B796B0F,E28011700000020A2B798447,E28011700000020A2B7865EF,E28011700000020A2B77C17F,E28011700000020A2B78CD6F,E28011700000020A2B7829BF,E28011700000020A2B779CE7,E28011700000020A2B77C16F,E28011700000020A2B784AC7,E28011700000020A2B79A8F0,E28011700000020A2B779DBE,E28011700000020A2B782847,E28011700000020A2B7890C6,E28011700000020A2B79A8C8,E28011700000020A2B784A96,E28011700000020A2B78B38F,E28011700000020A2B77FDBF,E28011700000020A2B79A8B8,E28011700000020A2B7754D6,E28011700000020A2B77716E,E28011700000020A2B791A86,E28011700000020A2B77E33F,E28011700000020A2B7913AF,E28011700000020A2B779D9E,E28011700000020A2B778BFF,E28011700000020A2B782827,E28011700000020A2B779C36,E28011700000020A2B7829CF,E28011700000020A2B77F457,E28011700000020A2B777006,E28011700000020A2B782837,E28011700000020A2B778B1F,E28011700000020A2B7984D8,E28011700000020A2B779247,E28011700000020A2B778B0F,E28011700000020A2B78CD4F,E28011700000020A2B79A15F,E28011700000020A2B784A81,E28011700000020A2B7972A0,E28011700000020A2B7754F6,E28011700000020A2B7958D7,E28011700000020A2B7958E7,E28011700000020A2B78B217,E28011700000020A2B79139F,E28011700000020A2B79A17F,E28011700000020A2B78C436,E28011700000020A2B78C446,E28011700000020A2B78299F,E28011700000020A2B785C77,E28011700000020A2B779257,E28011700000020A2B7890D6,E28011700000020A2B79513F,E28011700000020A2B77FDCF,E28011700000020A2B7951FF,E28011700000020A2B78652F,E28011700000020A2B798470,E28011700000020A2B77717E,E28011700000020A2B7913BF,E28011700000020A2B796B2F,E28011700000020A2B785CE6,E28011700000020A2B78B37F,E28011700000020A2B779277,E28011700000020A2B7913CF,E28011700000020A2B7972C0,E28011700000020A2B785CA7,E28011700000020A2B78911F,E28011700000020A2B785CF6,E28011700000020A2B7829AF,E28011700000020A2B77718E,E28011700000020A2B798480,E28011700000020A2B779C16,E28011700000020A2B77E35F,E28011700000020A2B78CD5F,E28011700000020A2B79A847,E28011700000020A2B784A86,E28011700000020A2B789006,E28011700000020A2B77715E,E28011700000020A2B79A16F,E28011700000020A2B77F447,E28011700000020A2B79514F,E28011700000020A2B779267,E28011700000020A2B796B1F,E28011700000020A2B78C426,E28011700000020A2B78912F,E28011700000020A1615DA5B,E28011700000020A2B798457,E28011700000020A2B7958B7,E28011700000020A2B78F9DF,E28011700000020A2B779CF7,E28011700000020A2B7770B6,E28011700000020A2B7770A7,E28011700000020A2B79A817,E28011700000020A2B784AB7,E28011700000020A2B777067,E28011700000020A2B77C8C7,E28011700000020A2B79857F,E28011700000020A2B778B3E,E28011700000020A2B7958C7,E28011700000020A2B782817,E28011700000020A2B797258,E28011700000020A2B7984F8,E28011700000020A2B79A837,E28011700000020A2B79858F,E28011700000020A2B7865DF,E28011700000020A2B77E34F,E28011700000020A2B77E32F,E28011700000020A2B77C8D7,E28011700000020A2B78651F,E28011700000020A2B78CD3F,E28011700000020A2B79A827,E28011700000020A2B77C8E7,E28011700000020A2B795840,E28011700000020A2B789016,E28011700000020A2B778BCF
';

        $rfid_list = $rfid ? (array)db('rfid')->where("find_in_set(rfid, '".$rfid."')>0 and status<>3")->select() : array();

//      $rfid_list = $rfid ? (array)db('rfid')->where("find_in_set(rfid, '".$rfid."')>0 and (status=1 or status =3) and (device_id=0 or device_id = ".$order['device_id']." )")->select() : array();//剔除掉已经其他柜子的rfid 查找多扣的rfid
        $order = Db::name('device_order')->alias('a')->join('dlc_device b','a.device_id = b.device_id')->where('order_id',1439)->find();

        if(empty($order)){
            write_log('closeDoor',"此订单不存在");
            $this->_return(-1,'此订单不存在',(object)array());
        }
      $deve=new DeviceController();
        $new_rfid = $deve->new_rfid($rfid_list,$order);
        $arrlist=array();
        $arrlist['type']=1;
//        if($type==6){
//            $arrlist['class']=2;//盘点
//
//        }else{
//            $arrlist['class']=1;//补货分类
//        }
        write_log('closeDoor',"nowRfid:".$rfid);
        write_log('closeDoor',"计算后返回数据new_rfid:".json_encode($new_rfid,true));
        write_log('closeDoor',"计算后返回数据new_rfid:".empty($new_rfid));
//        if(empty($new_rfid)){
//
//            $arrlist['data']=[];
//            Db::name('device_order')->where(['order_id'=>$order['order_id']])->update(['status'=>-1]);
//            write_log('closeDoor',"计算后返回数据arrlist:".json_encode($arrlist,true));
//            $mqtt->notifyH5(1,'关门成功,未补货!',$udid,$arrlist,$order['order_number']);
//            $this->_return(1,'关门成功,未补货',(object)array());
//        }
        //删除上次未完成数据
        Db::name('device_orderinfo')->where(['order_id'=>$order['order_id']])->delete();
        //修改设备的状态
        Db::name('device')->where(['device_id'=>$order['device_id']])->update(['doorstatus'=>0,'open_status'=>3]);
        //修改主订单状态
        $data['close_time'] = time();
        $data['status']     = 2;
        $data['new_rfid'] = json_encode($new_rfid);
        Db::name('device_order')->where(['order_id'=>$order['order_id']])->update($data);
        //获取原数据

        $deviceGoodsList = Db::name('device_goods')->alias('a')
            ->join('dlc_goods b','a.goods_id = b.goods_id','left')
            ->field('a.goods_id,a.inventory,a.rfid,b.title,b.img')
            ->where(['a.device_id'=>28,'a.inventory'=>['>', 0],'a.rfid'=>['<>','']])
            ->select();
        //新数据
        $list = array();
        if($new_rfid){
            $list = Db::name('goods')->where(array('goods_id'=> array('in', array_keys($new_rfid))))
                ->column('goods_id,title,img');
            foreach($new_rfid as $k => $v){
                $list[$k] = array(
                    'goods_id' => $k,
                    'inventory' => $v['num'],
                    'title' => $list[$k]['title'],
                    'rfid' => $v['rfid'],
                    'img' => $list[$k]['img'],
                );
            }
            $list = array_values($list);
            write_log('closeDoor',"list".json_encode($list,true));
        }
        //对比数据
        function _pri_search($itemDeviceGoods, $listDeviceGoods){
            foreach($listDeviceGoods as $v){
                $v['rfid'] = array_filter(explode(',', $v['rfid']));
                if($v['goods_id'] == $itemDeviceGoods['goods_id'])return $v;
            }
            return false;
        }

        $newlist = array();

        foreach ($list as $value) {
            $value['rfid'] = array_filter(explode(',', $value['rfid']));
            $oldDeviceGoods = _pri_search($value, $deviceGoodsList);
            if($oldDeviceGoods){

                $inrfid = array_diff($value['rfid'], $oldDeviceGoods['rfid']);
                $outrfid = array_diff($oldDeviceGoods['rfid'], $value['rfid']);

                if(!$inrfid && !$outrfid)
                {continue;}
               if($inrfid){$newlist[] = array(
                    'goods_id' => $value['goods_id'],
                    'inventory' => count($inrfid),
                    'title' => $value['title'],
                    'img' => $this->appUrl.'public'.$value['img'],
                    'outrfid' => array(),
                    'inrfid' => $inrfid,
                );
                continue;
                }
                if($outrfid){$newlist[] = array(
                    'goods_id' => $value['goods_id'],
                    'inventory' => -1 * count($outrfid),
                    'title' => $value['title'],
                    'img' => $this->appUrl.'public'.$value['img'],
                    'outrfid' => $outrfid,
                    'inrfid' => array(),
                );
               }
            }else{

                $newlist[] = array(
                    'goods_id' => $value['goods_id'],
                    'inventory' => $value['inventory'],
                    'title' => $value['title'],
                    'img' => $this->appUrl.'public'.$value['img'],
                    'outrfid' => array(),
                    'inrfid' => $value['rfid'],
                );
            }
        }


        write_log('closeDoor',"list1".json_encode($list,true));
        foreach ($deviceGoodsList as $value) {

            $value['rfid'] = array_filter(explode(',', $value['rfid']));
//			if($value['inventory'] <= 0)continue;
//			$newDeviceGoods = _pri_search($value, $list);
            if($value['inventory'] <= 0||$value['inventory']>0){
                continue;
            }else{
                $newDeviceGoods = _pri_search($value, $list);

            }
            if($newDeviceGoods){
                continue;
            }else{
                $newlist[] = array(
                    'goods_id' => $value['goods_id'],
                    'inventory' => -1 * $value['inventory'],
                    'title' => $value['title'],
                    'img' => $this->appUrl.'public'.$value['img'],
                    'outrfid' => $value['rfid'],
                    'inrfid' => array(),
                );

            }

        }
        print_r($newlist);
        die;

    }

    public function veciorder(){


        $deviceOrder = Db::name('device_order')->where('order_id',1450)->find();
        if(empty($deviceOrder))  $this->_return(-1,'没有这个补货订单');
        //检查是否有开门中补货订单

        //$deviceOrderInfo = Db::name('device_orderinfo')->where(['order_id'=>$deviceOrder['order_id']])->select();
        $deviceOrderInfo = json_decode($deviceOrder['new_rfid'], true);

        //清空原来的商品//修改rfid的状态
//		$rfidWhere = [
//				'status' => 2,
//				'device_id' => $device['device_id'],
//		];
//		Db::name('rfid')->where($rfidWhere)->update(['device_id' => 0, 'status' => 1]);
//		Db::name('device_goods')->where(['device_id'=>$device['device_id']])->update(array('rfid' => '', 'inventory' => 0));
//
//        if(!$deviceOrderInfo){//如果没有补进商品的话就订单状态改为3
//            Db::name('device_order')->where($deviceMap)->update(['status'=>3]);
//            $this->_return(1,'补货已完成，没有补进商品！');
//        }

        //Db::startTrans();//开启事务
        //try{

        foreach ($deviceOrderInfo as $key => $value) {
            //新增一条补货操作日志表

            $find=db('rfid')->where(['rfid'=>['in',$value['rfid']]])->find();
            if($find['status']==3){
                $info_order_id= db('device_orderinfo')->where(['rfid'=>['in',$value['rfid']]])->value('order_id');
                db('order')->where('order_id',$info_order_id)->update(['is_red'=>2]);
            }
            //修改rfid的状态
            $rfid['device_id'] = 28;//设备ID
            $rfid['shop_id']   = $deviceOrder['shop_id'];   //商品ID
            $rfid['status']    = 2;
            $rfidWhere = "find_in_set(rfid, '".$value['rfid']."')>0";
            Db::name('rfid')->where($rfidWhere)->update($rfid);

            //获取商品信息
            $goods = Db::name('goods')->where(['goods_id'=>$key])->find();
            //新增或修改设备的rfid

            $deviceGoods = Db::name('device_goods')->where(['device_id'=>28,'goods_id'=>$key])->find();

            if(!empty($deviceGoods)){
                $addGoods = array();

                if($deviceGoods['goods_id']==$key){

                    $addGoods['rfid']      = $value['rfid'].','.$deviceGoods['rfid'];
                    $addGoods['inventory'] = $value['num']+$deviceGoods['inventory'];
                }else{

                    $addGoods['rfid']      = $value['rfid'];
                    $addGoods['inventory'] = $value['num'];
                }


                print_r($addGoods);
//                $re1 = Db::name('device_goods')->where(['device_goods_id'=>$deviceGoods['device_goods_id']])->update($addGoods);

            }else{
                $addGoods = array();
                $addGoods['inventory'] = $value['num'];
                $addGoods['rfid']      = $value['rfid'];
                $addGoods['device_id'] = 28;
                $addGoods['goods_id']  = $key;
                $addGoods['price']     = $goods['price'];
                $addGoods['ctime']     = time();
//                print_r($addGoods);
//                $re1 = Db::name('device_goods')->insert($addGoods);

            }
        }


    }


}
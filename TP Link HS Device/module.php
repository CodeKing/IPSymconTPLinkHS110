<?php

declare(strict_types=1);

class TPLinkHS110 extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('modelselection', 1);
        $this->RegisterPropertyInteger('stateinterval', 0);
        $this->RegisterPropertyInteger('systeminfointerval', 0);
        $this->RegisterPropertyBoolean('extendedinfo', false);
        $this->RegisterPropertyString('softwareversion', '');
        $this->RegisterPropertyFloat('hardwareversion', 0);
        $this->RegisterPropertyString('type', '');
        $this->RegisterPropertyString('model', '');
        $this->RegisterPropertyString('mac', '');
        $this->RegisterPropertyString('deviceid', '');
        $this->RegisterPropertyString('hardwareid', '');
        $this->RegisterPropertyString('firmwareid', '');
        $this->RegisterPropertyString('oemid', '');
        $this->RegisterPropertyString('alias', '');
        $this->RegisterPropertyString('devicename', '');
        $this->RegisterPropertyInteger('rssi', 0);
        $this->RegisterPropertyBoolean('ledoff', false);
        $this->RegisterPropertyFloat('latitude', 0);
        $this->RegisterPropertyFloat('longitude', 0);
        $this->RegisterAttributeBoolean('Timeout', false);
        $this->RegisterAttributeInteger('NumberRequests', 0);
        $this->RegisterTimer('StateUpdate', 0, 'TPLHS_StateTimer(' . $this->InstanceID . ');');
        $this->RegisterTimer('SystemInfoUpdate', 0, 'TPLHS_SystemInfoTimer(' . $this->InstanceID . ');');
        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 1);
        $this->EnableAction('State');
        $model = $this->ReadPropertyInteger('modelselection');
        if ($model == 2) {
            $this->RegisterProfile('TPLinkHS.Milliampere', '', '', ' mA', 0, 0, 0, 0, 2);

            $this->RegisterVariableFloat('Voltage', $this->Translate('Voltage'), 'Volt.230', 2);
            $this->RegisterVariableFloat('Power', $this->Translate('Power'), 'Watt.14490', 3);
            $this->RegisterVariableFloat('Current', $this->Translate('Electricity'), 'TPLinkHS.Milliampere', 4);
            $this->RegisterVariableFloat('Work', $this->Translate('Work'), 'Electricity', 5);
        }
        $this->ValidateConfiguration();
    }

    private function ValidateConfiguration()
    {
        // Types HS100, HS105, HS110, HS200
        $host = $this->ReadPropertyString('Host');

        //IP TP Link check
        if (!filter_var($host, FILTER_VALIDATE_IP) === false) {
            //IP ok
            $ipcheck = true;
        } else {
            $ipcheck = false;
        }

        //Domain TP Link Device check
        if (!$this->is_valid_localdomain($host) === false) {
            //Domain ok
            $domaincheck = true;
        } else {
            $domaincheck = false;
        }

        if ($domaincheck === true || $ipcheck === true) {
            $hostcheck = true;
            $this->SetStatus(102);
        } else {
            $hostcheck = false;
            $this->SetStatus(203); //IP Adresse oder Host ist ungültig
        }
        $extendedinfo = $this->ReadPropertyBoolean('extendedinfo');
        if ($extendedinfo) {
            $this->SendDebug('TP Link:', 'extended info activ', 0);
        }
        $this->SetStateInterval($hostcheck);
        $this->SetSystemInfoInterval($hostcheck);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {

        switch ($Message) {
            case IM_CHANGESTATUS:
                if ($Data[0] === IS_ACTIVE) {
                    $this->ApplyChanges();
                }
                break;

            case IPS_KERNELMESSAGE:
                if ($Data[0] === KR_READY) {
                    $this->ApplyChanges();
                }
                break;

            default:
                break;
        }
    }

    public function StateTimer()
    {
        $timeout = $this->ReadAttributeBoolean('Timeout');
        if (!$timeout) {
            $this->GetSystemInfo();
        }
    }

    public function SystemInfoTimer()
    {
        $timeout = $this->ReadAttributeBoolean('Timeout');
        if (!$timeout) {
            $this->GetRealtimeCurrent();
        }
    }

    /** ResetWork
     * @return bool
     */
    public function ResetWork()
    {
        $result = SetValueFloat($this->GetIDForIdent('Work'), 0.0);
        return $result;
    }

    protected function SetStateInterval($hostcheck)
    {
        if ($hostcheck) {
            $devicetype    = $this->ReadPropertyInteger('modelselection');
            $stateinterval = $this->ReadPropertyInteger('stateinterval');
            $interval      = $stateinterval * 1000;
            if ($devicetype == 2) {
                $this->SetTimerInterval('StateUpdate', $interval);
            } else {
                $this->SetTimerInterval('StateUpdate', $interval);
            }
        }
    }

    protected function SetSystemInfoInterval($hostcheck)
    {
        if ($hostcheck) {
            $devicetype   = $this->ReadPropertyInteger('modelselection');
            $infointerval = $this->ReadPropertyInteger('systeminfointerval');
            $interval     = $infointerval * 1000;
            if ($devicetype == 2) {
                $this->SetTimerInterval('SystemInfoUpdate', $interval);
            } else {
                $this->SetTimerInterval('SystemInfoUpdate', 0);
            }
        }
    }

    protected function decrypt($cypher_text, $first_key = 0xAB)
    {
        $header        = substr($cypher_text, 0, 4);
        $header_length = unpack('N*', $header)[1];
        $cypher_text   = substr($cypher_text, 4);
        $buf           = unpack('c*', $cypher_text);
        $key           = $first_key;
        //$nextKey = "";
        for ($i = 1; $i < count($buf) + 1; $i++) {
            $nextKey = $buf[$i];
            $buf[$i] = $buf[$i] ^ $key;
            $key     = $nextKey;
        }
        $array_map     = array_map('chr', $buf);
        $clear_text    = implode('', $array_map);
        $cypher_length = strlen($clear_text);
        if ($header_length !== $cypher_length) {
            trigger_error("Length in header ({$header_length}) doesn't match actual message length ({$cypher_length}).");
        }
        return $clear_text;
    }

    protected function encrypt($clear_text, $first_key = 0xAB)
    {
        $buf = unpack('c*', $clear_text);
        $key = $first_key;
        for ($i = 1; $i < count($buf) + 1; $i++) {
            $buf[$i] = $buf[$i] ^ $key;
            $key     = $buf[$i];
        }
        $array_map  = array_map('chr', $buf);
        $clear_text = implode('', $array_map);
        $length     = strlen($clear_text);
        $header     = pack('N*', $length);
        return $header . $clear_text;
    }

    protected function connectToSocket()
    {
        $host = $this->ReadPropertyString('Host');
        if (!($sock1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            $errorcode = socket_last_error();
            $errormsg  = socket_strerror($errorcode);
            $this->SendDebug('TP Link Socket:', "Couldn't create socket: [" . $errorcode . '] ' . $errormsg, 0);
            $this->AddNumberRequest();
            die("Couldn't create socket: [$errorcode] $errormsg \n");
        }
        $this->SendDebug('TP Link:', 'Create Socket', 0);

        //Connect socket to remote server
        if (!socket_connect($sock1, $host, 9999)) {
            $errorcode = socket_last_error();
            $errormsg  = socket_strerror($errorcode);
            $this->SendDebug('TP Link Socket:', 'Could not connect: [' . $errorcode . '] ' . $errormsg, 0);
            $this->AddNumberRequest();
            die("Could not connect: [$errorcode] $errormsg \n");
        }
        $this->SendDebug('TP Link:', 'Connection established', 0);
        $this->DeleteNumberRequest();
        return $sock1;
    }

    protected function AddNumberRequest()
    {
        $i = $this->ReadAttributeInteger('NumberRequests');
        $i = $i + 1;
        $this->SendDebug('TP Link Socket:', "Couldn't connect socket " . $i . ' times', 0);
        $this->WriteAttributeInteger('NumberRequests', $i);
        if ($i > 15) {
            $this->WriteAttributeBoolean('Timeout', true);
        }
    }

    protected function DeleteNumberRequest()
    {
        $this->WriteAttributeInteger('NumberRequests', 0);
        $this->WriteAttributeBoolean('Timeout', false);
    }

    protected function sendToSocket($messageToSend, $sock)
    {
        $this->SendDebug('TP Link Socket:', 'Send Command: ' . $messageToSend, 0);
        $message = $this->encrypt($messageToSend);

        //Send the message to the server
        if (!socket_send($sock, $message, strlen($message), 0)) {
            $errorcode = socket_last_error();
            $errormsg  = socket_strerror($errorcode);
            $this->SendDebug('TP Link Socket:', 'Could not send data: [' . $errorcode . '] ' . $errormsg, 0);
            die("Could not send data: [$errorcode] $errormsg \n");
        }
        $this->SendDebug('TP Link:', 'Message send successfully', 0);
    }

    protected function getResultFromSocket($sock)
    {
        //Now receive reply from server
        $buf = '';
		if (socket_recv($sock, $buf, 2048, 0) === false) {
            $errorcode = socket_last_error();
            $errormsg  = socket_strerror($errorcode);
            $this->SendDebug('TP Link Socket:', 'Could not receive data: [' . $errorcode . '] ' . $errormsg, 0);
            die("Could not receive data: [$errorcode] $errormsg \n");
        }
        return $buf;
    }

    protected function SendToTPLink($command)
    {
        $sock = $this->connectToSocket();
        $this->sendToSocket($command, $sock);
        $buf    = $this->getResultFromSocket($sock);
        $result = json_decode($this->decrypt($buf));
        socket_close($sock);
        $this->SendDebug('TP Link Socket:', 'Result: ' . json_encode($result), 0);
        return $result;
    }

    //System Commands
    //========================================

    /** Get System Info (Software & Hardware Versions, MAC, deviceID, hwID etc.)
     * @return array
     */
    public function GetSystemInfo()
    {
        $systeminfo = [];
        $command     = '{"system":{"get_sysinfo":{}}}';
        $result      = $this->SendToTPLink($command);
        if(empty($result))
        {
            $this->SendDebug('TP Link:', 'Result empty', 0);
        }
        else
        {
            $systeminfo  = $result->system->get_sysinfo;
            if(property_exists($systeminfo, 'err_code'))
            {
                $err_code    = intval($systeminfo->err_code);
            }
            else
            {
                $err_code      = '';
            }
            if(property_exists($systeminfo, 'sw_ver'))
            {
                $sw_ver      = $systeminfo->sw_ver;
            }
            else
            {
                $sw_ver      = '';
            }
            if(property_exists($systeminfo, 'hw_ver'))
            {
                $hw_ver      = floatval($systeminfo->hw_ver);
            }
            else
            {
                $hw_ver      = '';
            }
            if(property_exists($systeminfo, 'type'))
            {
                $type        = $systeminfo->type;
            }
            else
            {
                $type      = '';
            }
            if(property_exists($systeminfo, 'model'))
            {
                $model       = $systeminfo->model;
            }
            else
            {
                $model      = '';
            }
            if(property_exists($systeminfo, 'mac'))
            {
                $mac         = $systeminfo->mac;
            }
            else
            {
                $mac      = '';
            }
            if(property_exists($systeminfo, 'deviceId'))
            {
                $deviceId    = $systeminfo->deviceId;
            }
            else
            {
                $deviceId      = '';
            }
            if(property_exists($systeminfo, 'hwId'))
            {
                $hwId        = $systeminfo->hwId;
            }
            else
            {
                $hwId      = '';
            }
            if(property_exists($systeminfo, 'fwId'))
            {
                $fwId        = $systeminfo->fwId;
            }
            else
            {
                $fwId      = '';
            }
            if(property_exists($systeminfo, 'oemId'))
            {
                $oemId       = $systeminfo->oemId;
            }
            else
            {
                $oemId      = '';
            }
            if(property_exists($systeminfo, 'alias'))
            {
                $alias       = $systeminfo->alias;
            }
            else
            {
                $alias      = '';
            }
            if(property_exists($systeminfo, 'dev_name'))
            {
                $dev_name    = $systeminfo->dev_name;
            }
            else
            {
                $dev_name      = '';
            }
            if(property_exists($systeminfo, 'icon_hash'))
            {
                $icon_hash   = $systeminfo->icon_hash;
            }
            else
            {
                $icon_hash      = '';
            }
            if(property_exists($systeminfo, 'relay_state'))
            {
                $relay_state = boolval($systeminfo->relay_state);
            }
            else
            {
                $relay_state      = false;
            }
            if(property_exists($systeminfo, 'on_time'))
            {
                $on_time     = intval($systeminfo->on_time);
            }
            else
            {
                $on_time      = '';
            }
            if(property_exists($systeminfo, 'active_mode'))
            {
                $active_mode = $systeminfo->active_mode;
            }
            else
            {
                $active_mode      = '';
            }
            if(property_exists($systeminfo, 'feature'))
            {
                $feature     = $systeminfo->feature;
            }
            else
            {
                $feature      = '';
            }
            if(property_exists($systeminfo, 'rssi'))
            {
                $rssi        = intval($systeminfo->rssi);
            }
            else
            {
                $rssi      = 0;
            }
            if(property_exists($systeminfo, 'led_off'))
            {
                $led_off     = boolval($systeminfo->led_off);
            }
            else
            {
                $led_off      = false;
            }
            if (isset($systeminfo->latitude)) {
                $latitude = floatval($systeminfo->latitude);
            } else {
                $latitude = 0;
            }
            if (isset($systeminfo->longitude)) {
                $longitude = floatval($systeminfo->longitude);
            } else {
                $longitude = 0;
            }
            SetValueBoolean($this->GetIDForIdent('State'), $relay_state);

            $extendedinfo = $this->ReadPropertyBoolean('extendedinfo');
            if ($extendedinfo) {
                SetValueString($this->GetIDForIdent('alias'), $alias);
            }
            $systeminfo = [
                'state'           => $relay_state,
                'errorcode'       => $err_code,
                'softwareversion' => $sw_ver,
                'hardwareversion' => $hw_ver,
                'type'            => $type,
                'model'           => $model,
                'mac'             => $mac,
                'deviceid'        => $deviceId,
                'hardwareid'      => $hwId,
                'firmwareid'      => $fwId,
                'oemid'           => $oemId,
                'alias'           => $alias,
                'devicename'      => $dev_name,
                'iconhash'        => $icon_hash,
                'ontime'          => $on_time,
                'active_mode'     => $active_mode,
                'feature'         => $feature,
                'rssi'            => $rssi,
                'ledoff'          => $led_off,
                'latitude'        => $latitude,
                'longitude'       => $longitude];
        }
        return $systeminfo;
    }

    public function WriteSystemInfo()
    {
        $systeminfo = $this->GetSystemInfo();
        IPS_SetProperty($this->InstanceID, 'softwareversion', $systeminfo['softwareversion']);
        IPS_SetProperty($this->InstanceID, 'hardwareversion', $systeminfo['hardwareversion']);
        IPS_SetProperty($this->InstanceID, 'type', $systeminfo['type']);
        IPS_SetProperty($this->InstanceID, 'model', $systeminfo['model']);
        IPS_SetProperty($this->InstanceID, 'mac', $systeminfo['mac']);
        IPS_SetProperty($this->InstanceID, 'deviceid', $systeminfo['deviceid']);
        IPS_SetProperty($this->InstanceID, 'hardwareid', $systeminfo['hardwareid']);
        IPS_SetProperty($this->InstanceID, 'firmwareid', $systeminfo['firmwareid']);
        IPS_SetProperty($this->InstanceID, 'oemid', $systeminfo['oemid']);
        IPS_SetProperty($this->InstanceID, 'alias', $systeminfo['alias']);
        IPS_SetProperty($this->InstanceID, 'devicename', $systeminfo['devicename']);
        IPS_SetProperty($this->InstanceID, 'rssi', $systeminfo['rssi']);
        IPS_SetProperty($this->InstanceID, 'ledoff', $systeminfo['ledoff']);
        IPS_SetProperty($this->InstanceID, 'latitude', $systeminfo['latitude']);
        IPS_SetProperty($this->InstanceID, 'longitude', $systeminfo['longitude']);
        IPS_ApplyChanges($this->InstanceID);
    }

    /** Reboot
     * @return mixed
     */
    public function Reboot()
    {
        $command = '{"system":{"reboot":{"delay":1}}}';
        return $this->SendToTPLink($command);
    }

    /**  Power On
     * @return mixed
     */
    public function PowerOn()
    {
        $command = '{"system":{"set_relay_state":{"state":1}}}';
        return $this->SendToTPLink($command);
    }

    /** Power Off
     * @return mixed
     */
    public function PowerOff()
    {
        $command = '{"system":{"set_relay_state":{"state":0}}}';
        return $this->SendToTPLink($command);
    }

    /** Reset (To Factory Settings)
     * @return mixed
     */
    public function Reset()
    {
        $command = '{"system":{"reset":{"delay":1}}}';
        return $this->SendToTPLink($command);
    }

    /** Turn Off Device LED (Night mode)
     * @return mixed
     */
    public function NightMode()
    {
        $command = '{"system":{"set_led_off":{"off":1}}}';
        return $this->SendToTPLink($command);
    }

    /** Set Device Alias
     * @param string $alias
     *
     * @return mixed
     */
    public function SetDeviceAlias(string $alias)
    {
        $command = '{"system":{"set_dev_alias":{"alias":"' . $alias . '"}}}';
        return $this->SendToTPLink($command);
    }

    /** Set MAC Address
     * @param string $mac
     *
     * @return mixed
     */
    public function SetMACAddress(string $mac)
    {
        // {"system":{"set_mac_addr":{"mac":"50-C7-BF-01-02-03"}}}
        $command = '{"system":{"set_mac_addr":{"mac":"' . $mac . '"}}}';
        return $this->SendToTPLink($command);
    }

    /** Set Device ID
     * @param string $deviceid
     *
     * @return mixed
     */
    public function SetDeviceID(string $deviceid)
    {
        $command = '{"system":{"set_device_id":{"deviceId":"' . $deviceid . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Set Hardware ID
    public function SetHardwareID(string $hardwareid)
    {
        $command = '{"system":{"set_hw_id":{"hwId":"' . $hardwareid . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Set Location
    public function SetLocation(float $longitude, float $latitude)
    {
        // {"system":{"set_dev_location":{"longitude":6.9582814,"latitude":50.9412784}}}
        $command = '{"system":{"set_dev_location":{"longitude":' . $longitude . ',"latitude":' . $latitude . '}}}';
        return $this->SendToTPLink($command);
    }

    // Perform uBoot Bootloader Check
    public function BootloaderCheck()
    {
        $command = '{"system":{"test_check_uboot":null}}';
        return $this->SendToTPLink($command);
    }

    // Get Device Icon
    public function GetDeviceIcon()
    {
        $command = '{"system":{"get_dev_icon":null}}';
        return $this->SendToTPLink($command);
    }

    // Set Device Icon
    public function SetDeviceIcon(string $icon, string $hash)
    {
        $command = '{"system":{"set_dev_icon":{"icon":"' . $icon . '","hash":"' . $hash . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Set Test Mode (command only accepted coming from IP 192.168.1.100)
    /*
    public function SetTestMode()
    {
        $command = '{"system":{"set_test_mode":{"enable":1}}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }
    */

    // Download Firmware from URL
    public function DownloadFirmware(string $url)
    {
        $command = '{"system":{"download_firmware":{"url":"http://' . $url . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Get Download State
    public function GetDownloadState()
    {
        $command = '{"system":{"get_download_state":{}}}';
        return $this->SendToTPLink($command);
    }

    // Flash Downloaded Firmware
    public function FlashDownloadedFirmware()
    {
        $command = '{"system":{"flash_firmware":{}}}';
        return $this->SendToTPLink($command);
    }

    // Check Config
    public function CheckConfig()
    {
        $command = '{"system":{"check_new_config":null}}';
        return $this->SendToTPLink($command);
    }

    // WLAN Commands
    // ========================================

    // Scan for list of available APs
    public function ScanAP()
    {
        $command = '{"netif":{"get_scaninfo":{"refresh":1}}}';
        return $this->SendToTPLink($command);
    }

    // Connect to AP with given SSID and Password
    public function ConnectAP(string $ssid, string $password)
    {
        $command = '{"netif":{"set_stainfo":{"ssid":"' . $ssid . '","password":"' . $password . '","key_type":3}}}';
        return $this->SendToTPLink($command);
    }

    // Cloud Commands
    // ========================================

    // Get Cloud Info (Server, Username, Connection Status)
    public function GetCloudInfo()
    {
        $command = '{"cnCloud":{"get_info":null}}';
        return $this->SendToTPLink($command);
    }

    // Get Firmware List from Cloud Server
    public function GetFirmwareList()
    {
        $command = '{"cnCloud":{"get_intl_fw_list":{}}}';
        return $this->SendToTPLink($command);
    }

    // Set Server URL
    public function SetServerURL(string $url)
    {
        // {"cnCloud":{"set_server_url":{"server":"devs.tplinkcloud.com"}}}
        $command = '{"cnCloud":{"set_server_url":{"server":"' . $url . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Connect with Cloud username & Password
    public function ConnectCloud(string $user, string $password)
    {
        // {"cnCloud":{"bind":{"username":"your@email.com", "password":"secret"}}}
        $command = '{"cnCloud":{"bind":{"username":"' . $user . '", "password":"' . $password . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Unregister Device from Cloud Account
    public function UnregisterFromCloud()
    {
        $command = '{"cnCloud":{"unbind":null}}';
        return $this->SendToTPLink($command);
    }

    // Time Commands
    // ========================================

    // Get Time
    public function GetTime()
    {
        $command = '{"time":{"get_time":null}}';
        return $this->SendToTPLink($command);
    }

    // Get Timezone
    public function GetTimezone()
    {
        $command = '{"time":{"get_timezone":null}}';
        return $this->SendToTPLink($command);
    }

    // Set Timezone
    public function SetTimezone()
    {
        $command = '{"time":{"set_timezone":{"year":2016,"month":1,"mday":1,"hour":10,"min":10,"sec":10,"index":42}}}';
        return $this->SendToTPLink($command);
    }

    // EMeter Energy Usage Statistics Commands
    // (for TP-Link HS110)
    // ========================================

    // Get Realtime Current and Voltage Reading
    public function GetRealtimeCurrent()
    {
        $command         = '{"emeter":{"get_realtime":{}}}';
        $result          = $this->SendToTPLink($command);
        $hardwareversion = $this->ReadPropertyFloat('hardwareversion');
        if ($hardwareversion == 1) {
            SetValueFloat($this->GetIDForIdent('Voltage'), floatval($result->emeter->get_realtime->voltage));
            $this->SendDebug('TP Link:', 'Voltage: ' . floatval($result->emeter->get_realtime->voltage), 0);
            SetValueFloat($this->GetIDForIdent('Current'), floatval($result->emeter->get_realtime->current * 1000.0));
            $this->SendDebug('TP Link:', 'Current: ' . floatval($result->emeter->get_realtime->current * 1000.0), 0);
            $power = floatval($result->emeter->get_realtime->power);
            $this->SendDebug('TP Link:', 'Power: ' . $power, 0);
            SetValueFloat($this->GetIDForIdent('Power'), $power);
            $previous_work = GetValueFloat($this->GetIDForIdent('Work'));
            $timefactor    = floatval($this->ReadPropertyInteger('systeminfointerval') / 3600.0);
            $work          = $previous_work + ($power * $timefactor);
            $this->SendDebug('TP Link:', 'Work: ' . $work, 0);
            SetValueFloat($this->GetIDForIdent('Work'), $work);
            return [
                'voltage' => floatval($result->emeter->get_realtime->voltage),
                'current' => floatval($result->emeter->get_realtime->current),
                'power'   => floatval($result->emeter->get_realtime->power),
                'work'    => $work];
        } else {
            SetValueFloat($this->GetIDForIdent('Voltage'), floatval($result->emeter->get_realtime->voltage_mv) / 1000);
            $this->SendDebug('TP Link:', 'Voltage: ' . floatval($result->emeter->get_realtime->voltage_mv) / 1000, 0);
            SetValueFloat($this->GetIDForIdent('Current'), floatval($result->emeter->get_realtime->current_ma * 1000.0));
            $this->SendDebug('TP Link:', 'Current: ' . floatval($result->emeter->get_realtime->current_ma * 1000.0), 0);
            $power = floatval($result->emeter->get_realtime->power_mw);
            $this->SendDebug('TP Link:', 'Power: ' . $power, 0);
            SetValueFloat($this->GetIDForIdent('Power'), $power);
            $previous_work = GetValueFloat($this->GetIDForIdent('Work'));
            $timefactor    = floatval($this->ReadPropertyInteger('systeminfointerval') / 3600.0);
            $work          = $previous_work + ($power * $timefactor);
            $this->SendDebug('TP Link:', 'Work: ' . $work, 0);
            SetValueFloat($this->GetIDForIdent('Work'), $work);
            return [
                'voltage' => floatval($result->emeter->get_realtime->voltage_mv) / 1000,
                'current' => floatval($result->emeter->get_realtime->current_ma),
                'power'   => floatval($result->emeter->get_realtime->power_mw),
                'work'    => $work];
        }
    }

    // Get EMeter VGain and IGain Settings
    public function GetEMeterVGain()
    {
        $command = '{"emeter":{"get_vgain_igain":{}}}';
        return $this->SendToTPLink($command);
    }

    // Set EMeter VGain and Igain
    public function SetEMeterVGain(int $vgain, int $igain)
    {
        // {"emeter":{"set_vgain_igain":{"vgain":13462,"igain":16835}}}
        $command = '{"emeter":{"set_vgain_igain":{"vgain":' . $vgain . ',"igain":' . $igain . '}}}';
        return $this->SendToTPLink($command);
    }

    // Start EMeter Calibration
    public function StartEMeterCalibration(int $vgain, int $igain)
    {
        // {"emeter":{"start_calibration":{"vtarget":13462,"itarget":16835}}}
        $command = '{"emeter":{"start_calibration":{"vtarget":' . $vgain . ',"itarget":' . $igain . '}}}';
        return $this->SendToTPLink($command);
    }

    // Get Daily Statistic for given Month
    public function GetDailyStatistic(int $year)
    {
        $command = '{"emeter":{"get_daystat":{"month":1,"year":' . $year . '}}}';
        return $this->SendToTPLink($command);
    }

    // Get Montly Statistic for given Year
    public function GetMontlyStatistic(int $year)
    {
        $command = '{"emeter":{""get_monthstat":{"year":' . $year . '}}}';
        return $this->SendToTPLink($command);
    }

    // Erase All EMeter Statistics
    public function EraseAllEMeterStatistics()
    {
        $command = '{"emeter":{"erase_emeter_stat":null}}';
        return $this->SendToTPLink($command);
    }

    // Schedule Commands
    // (action to perform regularly on given weekdays)
    // ========================================

    // Get Next Scheduled Action
    public function GetNextScheduledAction()
    {
        $command = '{"schedule":{"get_next_action":null}}';
        return $this->SendToTPLink($command);
    }

    // Get Schedule Rules List
    public function GetScheduleRulesList()
    {
        $command = '{"schedule":{"get_rules":null}}';
        return $this->SendToTPLink($command);
    }

    // Add New Schedule Rule
    /*
    public function AddNewScheduleRule()
    {
        // {"schedule":{"add_rule":{"stime_opt":0,"wday":[1,0,0,1,1,0,0],"smin":1014,"enable":1,"repeat":1,"etime_opt":-1,"name":"lights on","eact":-1,"month":0,"sact":1,"year":0,"longitude":0,"day":0,"force":0,"latitude":0,"emin":0},"set_overall_enable":{"enable":1}}}
        $command = '{"schedule":{"add_rule":{"stime_opt":0,"wday":[1,0,0,1,1,0,0],"smin":1014,"enable":1,"repeat":1,"etime_opt":-1,"name":"lights on","eact":-1,"month":0,"sact":1,"year":0,"longitude":0,"day":0,"force":0,"latitude":0,"emin":0},"set_overall_enable":{"enable":1}}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }

    // Edit Schedule Rule with given ID
    public function EditScheduleRule(string $id)
    {
        // {"schedule":{"edit_rule":{"stime_opt":0,"wday":[1,0,0,1,1,0,0],"smin":1014,"enable":1,"repeat":1,"etime_opt":-1,"id":"4B44932DFC09780B554A740BC1798CBC","name":"lights on","eact":-1,"month":0,"sact":1,"year":0,"longitude":0,"day":0,"force":0,"latitude":0,"emin":0}}}
        $command = '{"schedule":{"edit_rule":{"stime_opt":0,"wday":[1,0,0,1,1,0,0],"smin":1014,"enable":1,"repeat":1,"etime_opt":-1,"id":"'.$id.'","name":"lights on","eact":-1,"month":0,"sact":1,"year":0,"longitude":0,"day":0,"force":0,"latitude":0,"emin":0}}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }

    // Delete Schedule Rule with given ID
    public function DeleteScheduleRule(string $id)
    {
        // {"schedule":{"delete_rule":{"id":"4B44932DFC09780B554A740BC1798CBC"}}}
        $command = '{"schedule":{"delete_rule":{"id":"'.$id.'"}}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }

    // Delete All Schedule Rules and Erase Statistics
    public function DeleteAllScheduleRules()
    {
        // {"schedule":{"delete_all_rules":null,"erase_runtime_stat":null}}
        $command = '{"schedule":{"delete_all_rules":null,"erase_runtime_stat":null}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }
    */

    // Countdown Rule Commands
    // (action to perform after number of seconds)

    // Get Rule (only one allowed)
    public function GetRule()
    {
        $command = '{"count_down":{"get_rules":null}}';
        return $this->SendToTPLink($command);
    }

    // Add New Countdown Rule
    public function AddNewCountdownRule(int $delay, string $name)
    {
        // {"count_down":{"add_rule":{"enable":1,"delay":1800,"act":1,"name":"turn on"}}}
        $command = '{"count_down":{"add_rule":{"enable":1,"delay":' . $delay . ',"act":1,"name":"' . $name . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Edit Countdown Rule with given ID
    public function EditCountdownRule(string $id, int $delay, string $name)
    {
        // {"count_down":{"edit_rule":{"enable":1,"id":"7C90311A1CD3227F25C6001D88F7FC13","delay":1800,"act":1,"name":"turn on"}}}
        $command = '{"count_down":{"edit_rule":{"enable":1,"id":"' . $id . '","delay":' . $delay . ',"act":1,"name":"' . $name . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Delete Countdown Rule with given ID
    public function DeleteCountdownRule(string $id)
    {
        // {"count_down":{"delete_rule":{"id":"7C90311A1CD3227F25C6001D88F7FC13"}}}
        $command = '{"count_down":{"delete_rule":{"id":"' . $id . '"}}}';
        return $this->SendToTPLink($command);
    }

    // Delete All Coundown Rules
    public function DeleteAll()
    {
        // {"count_down":{"delete_all_rules":null}}
        $command = '{"count_down":{"delete_all_rules":null}}';
        return $this->SendToTPLink($command);
    }

    // Anti-Theft Rule Commands (aka Away Mode)
    // (period of time during which device will be randomly turned on and off to deter thieves)
    // ========================================

    // Get Anti-Theft Rules List
    public function GetAntiTheftRules()
    {
        $command = '{"anti_theft":{"get_rules":null}}';
        return $this->SendToTPLink($command);
    }

    // Delete All Anti-Theft Rules
    public function DeleteAllAntiTheftRules()
    {
        $command = '{"anti_theft":{"delete_all_rules":null}}';
        return $this->SendToTPLink($command);
    }

    // Add New Anti-Theft Rule
    /*
    public function AddNewAntiTheftRule()
    {
        // {"anti_theft":{"add_rule":{"stime_opt":0,"wday":[0,0,0,1,0,1,0],"smin":987,"enable":1,"frequency":5,"repeat":1,"etime_opt":0,"duration":2,"name":"test","lastfor":1,"month":0,"year":0,"longitude":0,"day":0,"latitude":0,"force":0,"emin":1047},"set_overall_enable":1}}
        $command = '{"anti_theft":{"add_rule":{"stime_opt":0,"wday":[0,0,0,1,0,1,0],"smin":987,"enable":1,"frequency":5,"repeat":1,"etime_opt":0,"duration":2,"name":"test","lastfor":1,"month":0,"year":0,"longitude":0,"day":0,"latitude":0,"force":0,"emin":1047},"set_overall_enable":1}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }

    // Edit Anti-Theft Rule with given ID
    public function EditAntiTheftRule()
    {
        $command = '{"anti_theft":{"edit_rule":{"stime_opt":0,"wday":[0,0,0,1,0,1,0],"smin":987,"enable":1,"frequency":5,"repeat":1,"etime_opt":0,"id":"E36B1F4466B135C1FD481F0B4BFC9C30","duration":2,"name":"test","lastfor":1,"month":0,"year":0,"longitude":0,"day":0,"latitude":0,"force":0,"emin":1047},"set_overall_enable":1}}';
        $result = $this->SendToTPLink($command);
        return $result;
    }
    */

    // Delete Anti-Theft Rule with given ID
    public function DeleteAntiTheftRule(string $id)
    {
        $command = '{"anti_theft":{"delete_rule":{"id":"' . $id . '"}}}';
        return $this->SendToTPLink($command);
    }

    public function ReceiveData($JSONString)
    {
        $data       = json_decode($JSONString);
        $objectid   = $data->Buffer->objectid;
        $values     = $data->Buffer->values;
        $valuesjson = json_encode($values);
        if (($this->InstanceID) == $objectid) {
            //Parse and write values to our variables
            //$this->WriteValues($valuesjson);
        }
    }

    protected function is_valid_localdomain($url)
    {

        $validation = false;
        /*Parse URL*/
        $urlparts = parse_url(filter_var($url, FILTER_SANITIZE_URL));
        /*Check host exist else path assign to host*/
        if (!isset($urlparts['host'])) {
            $urlparts['host'] = $urlparts['path'];
        }

//        if ($urlparts['host'] != '') {
//            /*Add scheme if not found*/
//            if (!isset($urlparts['scheme'])) {
//                $urlparts['scheme'] = 'http';
//            }
            /*Validation*/
//            if (checkdnsrr($urlparts['host'], 'A') && in_array($urlparts['scheme'], ['http', 'https']) && ip2long($urlparts['host']) === false) {
//                $urlparts['host'] = preg_replace('/^www\./', '', $urlparts['host']);
//                $url              = $urlparts['scheme'] . '://' . $urlparts['host'] . '/';

//                if (filter_var($url, FILTER_VALIDATE_URL) !== false && @get_headers($url)) {
//                    $validation = true;
//                }
//            }
//        }

        if (!$validation) {
            //echo $url." Its Invalid Domain Name.";
            $domaincheck = false;
            return $domaincheck;
        } else {
            //echo $url." is a Valid Domain Name.";
            $domaincheck = true;
            return $domaincheck;
        }

    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                $varid = $this->GetIDForIdent('State');
                SetValue($varid, $Value);
                if ($Value) {
                    $this->PowerOn();
                } else {
                    $this->PowerOff();
                }
                break;
            default:
                $this->SendDebug('Request Action:', 'Invalid ident', 0);
        }
    }

    protected function GetLEDState()
    {
        $state = $this->ReadPropertyBoolean('ledoff');
        if ($state) {
            $led_state = 'on';
        } else {
            $led_state = 'off';
        }
        return $led_state;
    }

    //Profile

    /**
     * register profiles.
     *
     * @param $Name
     * @param $Icon
     * @param $Prefix
     * @param $Suffix
     * @param $MinValue
     * @param $MaxValue
     * @param $StepSize
     * @param $Digits
     * @param $Vartype
     */
    protected function RegisterProfile($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Vartype)
    {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $Vartype); // 0 boolean, 1 int, 2 float, 3 string,
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != $Vartype) {
                $this->_debug('profile', 'Variable profile type does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileDigits($Name, $Digits); //  Nachkommastellen
        IPS_SetVariableProfileValues(
            $Name, $MinValue, $MaxValue, $StepSize
        ); // string $ProfilName, float $Minimalwert, float $Maximalwert, float $Schrittweite
    }

    /**
     * register profile association.
     *
     * @param $Name
     * @param $Icon
     * @param $Prefix
     * @param $Suffix
     * @param $MinValue
     * @param $MaxValue
     * @param $Stepsize
     * @param $Digits
     * @param $Vartype
     * @param $Associations
     */
    protected function RegisterProfileAssociation($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Vartype, $Associations)
    {
        if (is_array($Associations) && count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        }
        $this->RegisterProfile($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $Stepsize, $Digits, $Vartype);

        if (is_array($Associations)) {
            foreach ($Associations as $Association) {
                IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
            }
        } else {
            $Associations = $this->$Associations;
            foreach ($Associations as $code => $association) {
                IPS_SetVariableProfileAssociation($Name, $code, $this->Translate($association), $Icon, -1);
            }
        }

    }

    /**
     * send debug log.
     *
     * @param string $notification
     * @param string $message
     * @param int    $format       0 = Text, 1 = Hex
     */
    private function _debug(string $notification = null, string $message = null, $format = 0)
    {
        $this->SendDebug($notification, $message, $format);
    }

    /***********************************************************
     * Configuration Form
     ***********************************************************/

    /**
     * build configuration form.
     *
     * @return string
     */
    public function GetConfigurationForm()
    {
        // return current form
        return json_encode(
            [
                'elements' => $this->FormHead(),
                'actions'  => $this->FormActions(),
                'status'   => $this->FormStatus()]
        );
    }

    /**
     * return form configurations on configuration step.
     *
     * @return array
     */
    protected function FormHead()
    {
        $model           = $this->ReadPropertyInteger('modelselection');
        $softwareversion = $this->ReadPropertyString('softwareversion');
        $form            = [
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS type'],
            [
                'type'    => 'Select',
                'name'    => 'modelselection',
                'caption' => 'model',
                'options' => [
                    [
                        'label' => 'HS100',
                        'value' => 1],
                    [
                        'label' => 'HS110',
                        'value' => 2]]

            ],
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS device ip address'],
            [
                'name'    => 'Host',
                'type'    => 'ValidationTextBox',
                'caption' => 'IP adress'],
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS device state update interval'],
            [
                'name'    => 'stateinterval',
                'type'    => 'IntervalBox',
                'caption' => 'seconds']];
        if ($model == 2) {
            $form = array_merge_recursive(
                $form, [
                    [
                        'type'    => 'Label',
                        'caption' => 'TP Link HS device system info update interval'],
                    [
                        'name'    => 'systeminfointerval',
                        'type'    => 'IntervalBox',
                        'caption' => 'seconds']]
            );
        }
        if ($softwareversion == '') {
            $form = array_merge_recursive(
                $form, [
                    [
                        'type'    => 'Label',
                        'caption' => 'TP Link HS get system information'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Get system info',
                        'onClick' => 'TPLHS_WriteSystemInfo($id);']]
            );
        } else {
            $form = array_merge_recursive(
                $form, [
                    [
                        'type'    => 'Label',
                        'caption' => 'Data is from the TP Link HS device for information, change settings in the kasa app'],
                    [
                        'type'     => 'List',
                        'name'     => 'TPLinkInformation',
                        'caption'  => 'TP Link HS device information',
                        'rowCount' => 2,
                        'add'      => false,
                        'delete'   => false,
                        'sort'     => [
                            'column'    => 'model',
                            'direction' => 'ascending'],
                        'columns'  => [
                            [
                                'name'    => 'model',
                                'caption' => 'model',
                                'width'   => '100px',
                                'visible' => true],
                            [
                                'name'    => 'softwareversion',
                                'caption' => 'software version',
                                'width'   => '150px', ],
                            [
                                'name'    => 'hardwareversion',
                                'caption' => 'hardware version',
                                'width'   => '150px', ],
                            [
                                'name'    => 'type',
                                'caption' => 'type',
                                'width'   => 'auto', ],
                            [
                                'name'    => 'mac',
                                'caption' => 'mac',
                                'width'   => '150px', ],
                            [
                                'name'    => 'deviceid',
                                'caption' => 'device id',
                                'width'   => '200px', ],
                            [
                                'name'    => 'hardwareid',
                                'caption' => 'hardware id',
                                'width'   => '200px', ],
                            [
                                'name'    => 'firmwareid',
                                'caption' => 'firmware id',
                                'width'   => '200px', ],
                            [
                                'name'    => 'oemid',
                                'caption' => 'oem id',
                                'width'   => '200px', ],
                            [
                                'name'    => 'alias',
                                'caption' => 'alias',
                                'width'   => '150px', ],
                            [
                                'name'    => 'devicename',
                                'caption' => 'device name',
                                'width'   => '190px', ],
                            [
                                'name'    => 'rssi',
                                'caption' => 'rssi',
                                'width'   => '50px', ],
                            [
                                'name'    => 'ledoff',
                                'caption' => 'led state',
                                'width'   => '95px', ],
                            [
                                'name'    => 'latitude',
                                'caption' => 'latitude',
                                'width'   => '110px', ],
                            [
                                'name'    => 'longitude',
                                'caption' => 'longitude',
                                'width'   => '110px', ]],
                        'values'   => [
                            [
                                'model'           => $this->ReadPropertyString('model'),
                                'softwareversion' => $this->ReadPropertyString('softwareversion'),
                                'hardwareversion' => $this->ReadPropertyFloat('hardwareversion'),
                                'type'            => $this->ReadPropertyString('type'),
                                'mac'             => $this->ReadPropertyString('mac'),
                                'deviceid'        => $this->ReadPropertyString('deviceid'),
                                'hardwareid'      => $this->ReadPropertyString('hardwareid'),
                                'firmwareid'      => $this->ReadPropertyString('firmwareid'),
                                'oemid'           => $this->ReadPropertyString('oemid'),
                                'alias'           => $this->ReadPropertyString('alias'),
                                'devicename'      => $this->ReadPropertyString('devicename'),
                                'rssi'            => $this->ReadPropertyInteger('rssi'),
                                'ledoff'          => $this->GetLEDState(),
                                'latitude'        => $this->ReadPropertyFloat('latitude'),
                                'longitude'       => $this->ReadPropertyFloat('longitude')]]]]
            );
        }
        return $form;
    }

    /**
     * return form actions by token.
     *
     * @return array
     */
    protected function FormActions()
    {
        $form    = [
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS device'],
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS get system information'],
            [
                'type'    => 'Button',
                'caption' => 'Get system info',
                'onClick' => 'TPLHS_WriteSystemInfo($id);'],
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS Power On'],
            [
                'type'    => 'Button',
                'caption' => 'On',
                'onClick' => 'TPLHS_PowerOn($id);'],
            [
                'type'    => 'Label',
                'caption' => 'TP Link HS Power Off'],
            [
                'type'    => 'Button',
                'caption' => 'Off',
                'onClick' => 'TPLHS_PowerOff($id);'],
            [
                'type'    => 'Label',
                'caption' => 'Reset Work'],
            [
                'type'    => 'Button',
                'caption' => 'Reset Work',
                'onClick' => 'TPLHS_ResetWork($id);']];
        $timeout = $this->ReadAttributeBoolean('Timeout');
        if ($timeout) {
            $form = array_merge_recursive(
                $form, [
                    [
                        'type'    => 'Label',
                        'caption' => 'Get System Info'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Get System Info',
                        'onClick' => 'TPLHS_GetSystemInfo($id);']]
            );
        }
        return $form;
    }

    /**
     * return from status.
     *
     * @return array
     */
    protected function FormStatus()
    {
        $form = [
            [
                'code'    => 101,
                'icon'    => 'inactive',
                'caption' => 'Creating instance.'],
            [
                'code'    => 102,
                'icon'    => 'active',
                'caption' => 'instance created.'],
            [
                'code'    => 104,
                'icon'    => 'inactive',
                'caption' => 'interface closed.'],
            [
                'code'    => 201,
                'icon'    => 'inactive',
                'caption' => 'Please follow the instructions.'],
            [
                'code'    => 202,
                'icon'    => 'error',
                'caption' => 'special errorcode.'],
            [
                'code'    => 203,
                'icon'    => 'error',
                'caption' => 'IP Address is not valid.']];

        return $form;
    }
}

<?php
    
    use Entities\Application;
    use Entities\Version;
    use Entities\Tester;
    use Entities\Invitation;

    require_once __DIR__ . '/lib/cfpropertylist/CFPropertyList.php';
    require_once __DIR__ . '/core/index.php';
    require_once __DIR__ . '/tools.php';
    require_once __DIR__ . '/asn.php';

    function general_payload() {

            $payload = array();
            $payload['PayloadVersion'] = 1; // do not modify
            $payload['PayloadUUID'] = uniqid(); // must be unique

            //will be shown to the user.
            $payload['PayloadOrganization'] = "RunThisApp";
            return $payload;
    }

    function profile_service_payload($challenge, $key) {
        $payload = general_payload();

        $payload['PayloadType'] = "Profile Service"; // do not modify
        $payload['PayloadIdentifier'] = "com.runthisapp.mobileconfig.profile-service";

        // strings that show up in UI, customisable
        $payload['PayloadDisplayName'] = "RunThisApp Profile Service";
        $payload['PayloadDescription'] = "Install this profile to allow applications deployement from RunThisApp";
        $payload_content = array();
        //TODO: See Tools::current_url() function problem
        $payload_content['URL'] = 'http://' . $_SERVER['SERVER_NAME'].'/rta' . '/profile.php?key=' . $key;
        $payload_content['DeviceAttributes'] = array(
            'UDID', 
            'VERSION',
            'PRODUCT',              // ie. iPhone1,1 or iPod2,1
            'MAC_ADDRESS_EN0',      // WiFi MAC address
            'DEVICE_NAME',          // given device name "iPhone"
            // Items below are only available on iPhones
            'IMEI',
            'ICCID'
            );
        if (!empty($challenge)) {
            $payload_content['Challenge'] = $challenge;
        }

        $payload['PayloadContent'] = $payload_content;

            $plist = new CFPropertyList();

            $td = new CFTypeDetector();  
            $cfPayload = $td->toCFType( $payload );
            $plist->add( $cfPayload );
            return $plist->toXML(true);
    }

    //Step 1 check needed params:
    if (!isset($_GET['token']))
    {
        die('parameter token are needed (udid optional)');
    }

    //step 2 check that user is allowed to dl this app and this version:
    //TODO using $_GET["udid"]
    $entityManager = initDoctrine();
    date_default_timezone_set('Europe/Paris');

    $invitation = $entityManager->getRepository('Entities\Invitation')->findOneBy(array('token' => $_GET['token']));
    if ( $invitation == NULL ) {
        die('This invitation is not valid!');
    }

    if (!isset($_GET['udid'])) {
        $action = 'ENROLL';
        $isNotRegistered = ($invitation->getStatus() == Invitation::STATUS_SENT);
    } else {
        $action = 'DOWNLOAD';
    }

    $app = $invitation->getVersion()->getApplication()->getBundleId();
    $ver = $invitation->getVersion()->getVersion();

    if ($action == 'ENROLL' && $isNotRegistered) {

        $mail = $invitation->getTester()->getEmail();
        header('Content-Type: application/x-apple-aspen-config');

        $payload =  profile_service_payload('signed-auth-token', $_GET['token']);
        echo $payload;

        die ();
    }

    //step3 check that this app is already signed for this udid
    //if not sign it.
    function isAppSignedForUdid($udid, $app, $ver, $entityManager)
    {
            $application = $entityManager->getRepository('Entities\Application')->findOneBy(array('bundleId' => $app));

            //TODO: Token folder should be on version, not application
            //$version = $entityManager->getRepository('Entities\Version')->findOneBy(array('application' => $application->getId(), 'version' => $ver));

        $plistValue = __DIR__ . '/app/' . $application->getToken() . '/app_bundle/Payload/' . $application->getName() . '.app/embedded.mobileprovision';
        $plistValue = retreivePlistFromAsn($plistValue);
        if (empty($plistValue)) {
            die("unable to read plist from configuration profile challenge.");
        }
        $plist = new CFPropertyList();
        $plist->parse($plistValue, CFPropertyList::FORMAT_AUTO);
        $plistData = $plist->toArray();
        $provisionedDevices = $plistData['ProvisionedDevices'];
        $found = false;
        foreach ($provisionedDevices as $device)
        {
            if (strtoupper($device) == strtoupper($udid)) {
                    return true;
            }
        }
        return false;
    }

    $isAppSigned = isAppSignedForUdid($_GET['udid'], $app, $ver, $entityManager);

    //step4 provid link to dld app

    //TODO: generate PLIST file and provide link
    //$appLink = __DIR__ . '/app/' . $invitation->getVersion()->getApplication()->getToken() . '/app_bundle/Payload/';
    $application = $entityManager->getRepository('Entities\Application')->findOneBy(array('bundleId' => $app));
    
    $appLink = Tools::rel2abs('app/'. $application->getToken() .'.plist', Tools::current_url());
    $profileLink = Tools::rel2abs('app/'. $application->getToken() .'.mobileprovision', Tools::current_url());
    
?>
<html>
<head>

    <script src="js/jquery-1.6.1.min.js"></script>

    <script>

    function showTheLink(bool) {
            if (bool) {
                    $("#link").css("display", "inline");
                    $("#wait").css("display", "none");
            } else {
                    $("#link").css("display", "none");
                    $("#wait").css("display", "inline");
            }
    }

    </script>

    <style>
    #link {
            display: none;
    }
    </style>
</head>
<body>
    <span id="link">
        Here is your link: <a href="itms-services://?action=download-manifest&url=<?php echo $appLink; ?>">Application id <?php echo $app; ?></a>
        (Profile link: <a href="<?php echo $profileLink; ?>">Application profile</a>)
    </span>
    <?php
    
    if ($action == 'ENROLL') {
        if (!$isNotRegistered) {
            echo '<div>Your device is already registered!</div>';
        }
    }
    else if ($action == 'DOWNLOAD') {
        if ($isAppSigned) {
    ?>
            <script>
                showTheLink(true);
            </script>
        <?php
        } else {
        ?>
            <span id="wait">
                Please Wait...
                </span>
                <script>
                $.ajax({
                        type: "POST",
                        url: "sign.php",
                        data: "app=<?php echo $app ?>&ver=<?php echo $ver ?>&udid=<?php echo $_GET['udid'] ?>",
                        success: function(msg){	
                                showTheLink(true);
                        }
                 });
            </script>
    <?php
        }
    }
    ?>
</body>
</html>

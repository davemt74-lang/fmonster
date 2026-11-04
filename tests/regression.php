<?php
declare(strict_types=1);

define('FOODMONSTER_BOOTSTRAP', true);
require rtrim($argv[1], '/') . '/foodmonster-private/bootstrap.php';

$dbConfig = $argv[2] === 'mysql' ? ['driver'=>'mysql', 'host'=>'127.0.0.1', 'port'=>33371, 'name'=>$argv[3] ?? 'fm_regression', 'user'=>'root']
    : ['driver'=>'sqlite', 'path'=>':memory:'];
$db = new FMDatabase($dbConfig);
$db->migrate(fm_schema_directory());
$now = time();
$secret = 'whsec_RegressionOnly';
$cfg = ['app_env'=>'test','app_url'=>'http://127.0.0.1:38371','app_key'=>str_repeat('A',64),'mail'=>['enabled'=>false],'base_domain'=>'foodmonster.xyz'];
$state = ['checkout'=>[],'subscriptions'=>[],'invoices'=>[],'failPost'=>false,'failList'=>false,'partial'=>false,'keys'=>[],'reads'=>[]];
$cfg['stripe'] = ['enabled'=>true,'mode'=>'test','secret_key'=>'sk_test_RegressionOnly','webhook_secret'=>$secret,
    'test_transport'=>static function ($method, $path, $params, $key) use (&$state): array {
        if ($method === 'POST' && $path === '/checkout/sessions') {
            $state['keys'][] = $key;
            if ($state['failPost']) throw new FMError(503,'stripe_unavailable','Simulated cached Stripe API 500');
            $local = $params['client_reference_id'];
            $id = 'cs_test_' . $local;
            return $state['checkout'][$id] = ['id'=>$id,'client_reference_id'=>$local,'mode'=>'subscription','livemode'=>false,
                'status'=>'open','url'=>'https://checkout.stripe.com/c/pay/' . $id];
        }
        if ($method === 'GET' && $path === '/checkout/sessions') {
            if ($state['failList']) throw new FMError(503,'stripe_unavailable','Simulated Stripe lookup outage');
            if ($state['partial']) return ['object'=>'list','data'=>[],'has_more'=>true];
            return ['object'=>'list','data'=>array_values($state['checkout']),'has_more'=>false];
        }
        if ($method === 'POST' && str_ends_with($path,'/expire')) {
            $id = basename(dirname($path));
            $state['checkout'][$id]['status'] = 'expired';
            return $state['checkout'][$id];
        }
        if ($method === 'POST' && $path === '/billing_portal/sessions') return ['url'=>'https://billing.stripe.com/p/session/audit'];
        foreach (['/checkout/sessions/'=>'checkout','/subscriptions/'=>'subscriptions','/invoices/'=>'invoices'] as $prefix=>$resource) {
            if (str_starts_with($path,$prefix)) {
                $state['reads'][] = $path;
                return $state[$resource][basename($path)];
            }
        }
        throw new RuntimeException('Unexpected Stripe fixture request ' . $path);
    }];
FMSecurity::configure($cfg);
$checkCount = 0;
$check = static function (bool $pass, string $message) use (&$checkCount): void {
    if (!$pass) throw new RuntimeException($message);
    $checkCount++;
};
$expect = static function (string $error, callable $operation) use ($check): void {
    try { $operation(); }
    catch (FMError $exception) {
        $check($exception->errorCode === $error, 'Expected ' . $error . ', got ' . $exception->errorCode);
        return;
    }
    throw new RuntimeException('Expected ' . $error . ', operation succeeded');
};
$admin = str_repeat('a',32);
$buyer = str_repeat('b',32);
$sender = str_repeat('c',32);
$recipient = str_repeat('d',32);
$observer = str_repeat('e',32);
foreach ([$admin=>'admin',$buyer=>'buyer',$sender=>'sender',$recipient=>'recipient',$observer=>'observer'] as $id=>$name) {
    $db->execute('INSERT INTO users (id,name,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
        [$id,$name,$name.'@example.test','fixture',$id===$admin?'admin':'customer',$now,$now]);
}
FMLicensingUpgrade::apply($db,$admin);
FMLicensingUpgrade::apply($db,$admin);
$use = static function (string $id): void {
    $_SESSION = ['user_id'=>$id,'session_version'=>1,'issued_at'=>time(),'last_seen'=>time()];
};
$call = static fn (string $name, array $args = [], ?array $config = null) => FMCommerce::dispatch($name,$args,$db,$config??$cfg);
$rows = static fn (string $owner): array => $db->query('SELECT s.*, b.checkout_id, b.stripe_id, b.state AS billing_state
    FROM fm_subscriptions s LEFT JOIN fm_stripe_billing b ON b.subscription_id=s.id WHERE s.user_id=?',[$owner]);
$order = static fn (string $id): array => $db->one('SELECT s.status, s.expires_at, b.state AS billing_state, b.checkout_id FROM fm_subscriptions s
    JOIN fm_stripe_billing b ON b.subscription_id=s.id WHERE s.id=?',[$id]);
$use($admin);
$package = $call('admin.package_save',['name'=>'Cloud','description'=>'Fixture','currency'=>'USD','monthly_price_cents'=>3000,
    'quarterly_price_cents'=>8000,'annual_price_cents'=>30000,'max_installations'=>3,'hosting_modes'=>['cloud'],'published'=>true])['package']['id'];
$settings = ['package_id'=>$package,'billing_interval'=>'monthly','hosting_mode'=>'cloud'];
$use($buyer);
$state['failPost'] = true;
$expect('stripe_unavailable',fn()=>$call('stripe.checkout',$settings));
$expect('stripe_unavailable',fn()=>$call('stripe.checkout',$settings));
$failed = $rows($buyer)[0]['id'];
$check($call('account.summary')['onboarding'] === ['step'=>'payment','subscription_id'=>$failed],'Pending payment remains pending after an interrupted checkout');
$expect('subscription_required',fn()=>$call('installations.register',['business_name'=>'Unpaid','hosting_mode'=>'cloud','subdomain'=>'unpaid-test']));
$check(count(array_unique($state['keys'])) === 1 && $order($failed)['checkout_id'] === null,'Ambiguous retries used the original key');
$recover = ['subscription_id'=>$failed];
$use($observer);
$expect('billing_missing',fn()=>$call('stripe.recover',$recover));
$use($buyer);
$state['failList'] = true;
$expect('stripe_unavailable',fn()=>$call('stripe.recover',$recover));
$state['failList'] = false;
$state['partial'] = true;
$expect('checkout_review',fn()=>$call('stripe.recover',$recover));
$state['partial'] = false;
$check($order($failed)['status'] === 'pending_review','Incomplete provider checks preserve pending checkout');
$db->execute('UPDATE fm_subscriptions SET created_at=? WHERE id=?',[$now-24*3600,$failed]);
$expect('checkout_review',fn()=>$call('stripe.checkout',$settings));
$call('stripe.recover',$recover);
$check($order($failed)['status'] === 'checkout_expired','Confirmed absent checkout was closed');
$state['failPost'] = false;
$call('stripe.checkout',$settings);
$active = array_values(array_filter($rows($buyer),static fn($row)=>$row['billing_state']==='checkout'))[0];
$id = $active['id'];
$sessionId = $active['checkout_id'];
$check(count(array_unique($state['keys'])) === 2 && $id !== $failed,'New checkout created after recovery');
$call('stripe.recover',['subscription_id'=>$id]);
$check($state['checkout'][$sessionId]['status'] === 'expired' && $order($id)['status'] === 'checkout_expired','An open unpaid Stripe session is expired before clearing the local order');

$paused = $cfg;
$paused['stripe']['checkout_enabled'] = false;
$expect('checkout_paused',fn()=>$call('stripe.checkout',$settings,$paused));
$expect('checkout_required',fn()=>$call('subscription.request',$settings,$paused));
$list = $call('packages.list',[],$paused);
$check($list['stripe_ready'] && !$list['stripe_checkout_enabled'],'Sale pause is separate from Stripe readiness');
$check($call('packages.list')['stripe_checkout_enabled'],'Existing configs still enable checkout');

$call('stripe.checkout',$settings);
$pending = array_values(array_filter($rows($buyer),static fn($row)=>$row['billing_state']==='checkout'))[0];
$id = $pending['id'];
$sessionId = $pending['checkout_id'];
$check(isset($call('stripe.checkout',$settings,$paused)['url']),'Already-open checkout can still be completed while sales are paused');
$stripeId = 'sub_Regression';
$customer = 'cus_Regression';
$state['checkout'][$sessionId] = array_replace($state['checkout'][$sessionId],['status'=>'complete','subscription'=>$stripeId,'customer'=>$customer]);
$meta = ['food_monster_subscription_id'=>$id];
$paidUntil = $now + 30*86400;
$invoice = ['id'=>'in_Regression','livemode'=>false,'status'=>'paid','amount_paid'=>3000,'currency'=>'usd','customer'=>$customer,
    'parent'=>['subscription_details'=>['subscription'=>$stripeId,'metadata'=>$meta]],
    'lines'=>['data'=>[['parent'=>['subscription_item_details'=>['subscription_item'=>'si_Regression','proration'=>false]],
        'period'=>['start'=>$now-60,'end'=>$paidUntil],'pricing'=>['price_details'=>['price'=>'price_Regression']]]]]];
$remote = ['id'=>$stripeId,'status'=>'canceled','livemode'=>false,'customer'=>$customer,'metadata'=>$meta,
    'latest_invoice'=>['id'=>'in_Newer','status'=>'open'],
    'items'=>['data'=>[['id'=>'si_Regression','quantity'=>1,'current_period_start'=>$paidUntil,'current_period_end'=>$paidUntil+86400,
        'price'=>['id'=>'price_Regression','unit_amount'=>3000,'currency'=>'usd','recurring'=>['interval'=>'month','interval_count'=>1]]]]]];
$state['subscriptions'][$stripeId] = $remote;
$state['invoices'][$invoice['id']] = $invoice;
$event = static function (string $type, array $object, array $config, bool $valid = true) use ($db,$secret): void {
    $json = json_encode(['id'=>'evt_'.bin2hex(random_bytes(8)),'type'=>$type,'livemode'=>false,'api_version'=>FMStripeBilling::VERSION,'data'=>['object'=>$object]],JSON_THROW_ON_ERROR);
    $timestamp = time();
    FMStripeBilling::receive($db,$config,$json,"t=$timestamp,v1=".hash_hmac('sha256',"$timestamp.".$json,$valid?$secret:'invalid'));
};
$event('customer.subscription.deleted',$remote,$paused);
$check($order($id)['status'] === 'checkout_expired' && $order($id)['billing_state'] === 'cancelled','Cancellation recorded before payment');
$expect('invalid_signature',fn()=>$event('invoice.paid',$invoice,$paused,false));
$state['invoices'][$invoice['id']]['customer'] = 'cus_Wrong';
$expect('stripe_invoice_mismatch',fn()=>$event('invoice.paid',$invoice,$paused));
$operator = static fn(string $name, array $input = []) => FMOperatorDashboard::dispatch('admin.' . $name, $input, $db, $cfg);
$expect('admin_required', fn() => $operator('operations'));
$use($admin);
$billing = $operator('operations', ['view'=>'billing','email'=>'buyer@example.test'])['rows'];
$check(count($billing) === 1 && $billing[0]['reason'] === 'stripe_invoice_mismatch' && !isset($billing[0]['checkout_id']),
    'Verified mismatch appears in the billing queue without Stripe identifiers');
$use($buyer);
$state['invoices'][$invoice['id']] = $invoice;
$event('invoice.paid',$invoice,$paused);
$event('invoice.paid',$invoice,$paused);
$check(in_array('/invoices/in_Regression',$state['reads'],true),'Specific paid invoice retrieved');
$check((int)$order($id)['expires_at'] === $paidUntil && $order($id)['status'] === 'active'
    && $order($id)['billing_state'] === 'cancelled','Late payment credits exact paid period without restarting Stripe billing');
$check((int)$db->one("SELECT COUNT(*) AS n FROM audit_log WHERE event='subscription.stripe_paid'")['n'] === 1,'Duplicate payment does not duplicate credit');
$check(str_contains($call('stripe.portal',['subscription_id'=>$id],$paused)['url'],'billing.stripe.com'),'Billing portal remains available during sale pause');

$use($observer);
$call('stripe.checkout',$settings);
$lost = $rows($observer)[0];
$lostSession = $lost['checkout_id'];
$db->execute('UPDATE fm_stripe_billing SET checkout_id=NULL WHERE subscription_id=?',[$lost['id']]);
$lostRemote = array_replace($remote,['id'=>'sub_Completed','status'=>'active','metadata'=>['food_monster_subscription_id'=>$lost['id']]]);
$lostInvoice = $invoice;
$lostInvoice['parent']['subscription_details']['subscription'] = 'sub_Completed';
$lostRemote['latest_invoice'] = $lostInvoice;
$state['checkout'][$lostSession] = array_replace($state['checkout'][$lostSession],['status'=>'complete','subscription'=>'sub_Completed','customer'=>$customer]);
$state['subscriptions']['sub_Completed'] = $lostRemote;
$call('stripe.recover',['subscription_id'=>$lost['id']],$paused);
$check($order($lost['id'])['checkout_id'] === $lostSession && (int)$order($lost['id'])['expires_at'] === $paidUntil,
    'Completed checkout recovered its missing Stripe ID and paid access');
$renewal = $lostInvoice;
$renewal['id'] = 'in_Renewal';
$renewal['lines']['data'][0]['period'] = ['start'=>$paidUntil,'end'=>$paidUntil+30*86400];
$state['invoices'][$renewal['id']] = $renewal;
$event('invoice.paid',$renewal,$paused);
$check((int)$order($lost['id'])['expires_at'] === $paidUntil+30*86400 && $order($lost['id'])['billing_state'] === 'active',
    'Existing active subscription renews while checkout is paused');

$manual = $cfg;
$manual['stripe']['enabled'] = false;
foreach ([$sender,$recipient] as $owner) {
    $use($owner);
    $sub = $call('subscription.request',$settings,$manual)['subscription']['id'];
    $use($admin);
    $call('admin.subscription_activate',['subscription_id'=>$sub,'reference'=>'Audit fixture','expires_at'=>$now+86400]);
}
$use($sender);
$site = $call('installations.register',['business_name'=>'Audit restaurant','hosting_mode'=>'cloud','subdomain'=>'audit-transfer'])['installation']['id'];
$offer = ['installation_id'=>$site,'recipient_email'=>'recipient@example.test'];
$expect('recipient_unavailable',fn()=>$call('subdomains.offer',$offer));
$use($admin);
$expect('recipient_unavailable',fn()=>$call('admin.subdomain_offer',$offer));
$db->execute('UPDATE users SET email_verified_at=? WHERE id=?',[$now,$recipient]);
$use($sender);
$invite = $call('subdomains.offer',$offer)['transfer_id'];
$db->execute('UPDATE users SET email_verified_at=NULL WHERE id=?',[$recipient]);
$use($recipient);
$expect('recipient_unverified',fn()=>$call('subdomains.respond',['transfer_id'=>$invite,'decision'=>'accept']));
$check($db->one('SELECT user_id FROM fm_installations WHERE id=?',[$site])['user_id'] === $sender,'Rejected legacy acceptance left ownership intact');
$db->execute('UPDATE users SET email_verified_at=? WHERE id=?',[$now,$recipient]);
$call('subdomains.respond',['transfer_id'=>$invite,'decision'=>'accept']);
$check($db->one('SELECT user_id FROM fm_installations WHERE id=?',[$site])['user_id'] === $recipient,'Verified recipient can accept the same offer');
$use($admin);
$check($db->one('SELECT reason FROM fm_billing_reviews WHERE subscription_id=?',[$id]) === null,'Verified reconciliation clears a billing review');
$check($operator('operations',['view'=>'setup','email'=>'sender@example.test'])['rows'][0]['customer_id'] === $sender,'Active Cloud customer with pending setup appears');
$timeline = $operator('customer_timeline',['customer_id'=>$recipient]);
$check($timeline['sites'][0]['id'] === $site && !isset($timeline['customer']['password_hash']) && count($timeline['events']) > 0,'Customer timeline is scoped and safe');
$expect('invalid_filter',fn() => $operator('operations',['page'=>0]));
$check($operator('operations',['view'=>'customers','page'=>2])['rows'] === [],'Operations pagination works');
$check($operator('operations',['view'=>'hosting'])['rows'] === [],'Hosting queue runs on the migrated schema');
$db->execute('INSERT INTO fm_subdomain_terms (installation_id,expires_at) VALUES (?,?)',[$site,$now+60]);
$check($operator('operations',['view'=>'domains'])['rows'][0]['id'] === $site,'Expiring domain queue honors explicit renewals');
$db->execute('DELETE FROM fm_subdomain_terms WHERE installation_id=?',[$site]);
$db->execute('UPDATE fm_installations SET created_at=? WHERE id=?',[1709208000,$site]);
$expirySql = FMSubdomains::expirySql($db,0);
$check((int)$db->one("SELECT $expirySql AS expiry FROM fm_installations i LEFT JOIN fm_subdomain_terms t ON t.installation_id=i.id WHERE i.id=?",[$site])['expiry'] === 1740744000,
    'Queue anniversary preserves leap-day policy');
$use($sender);
$check($call('account.summary')['onboarding']['step'] === 'reserve','An active Cloud plan unlocks domain registration');
$onboardingSite = $call('installations.register',['business_name'=>'Setup restaurant','hosting_mode'=>'cloud','subdomain'=>'setup-restaurant'])['installation']['id'];
$check($call('account.summary')['onboarding'] === ['step'=>'owner','installation_id'=>$onboardingSite],'A saved reservation resumes at the owner step');
$ownerInput = ['installation_id'=>$onboardingSite,'business_name'=>'Setup restaurant','location_name'=>'Main',
    'first_name'=>'Setup','last_name'=>'Owner','timezone'=>'UTC','owner_password'=>'Fixture-Gelato-01!',
    'owner_password_confirm'=>'Fixture-Gelato-01!'];
$use($recipient);
$expect('installation_missing',fn()=>$call('installations.hosted_setup',$ownerInput));
$use($sender);
$call('installations.hosted_setup',$ownerInput);
$expect('setup_unavailable',fn()=>$call('installations.hosted_setup',$ownerInput));
$queued = $call('account.summary');
$check($queued['onboarding']['step'] === 'queued' && !str_contains(json_encode($queued),'Fixture-Gelato-01!'),'Queued setup resumes without exposing owner credentials');
$db->execute("UPDATE fm_hosted_setups SET state='failed' WHERE installation_id=?",[$onboardingSite]);
$check($call('account.summary')['onboarding']['step'] === 'failed','Provisioning failure is visible to its customer');
$db->execute("UPDATE fm_hosted_setups SET state='ready' WHERE installation_id=?",[$onboardingSite]);
$db->execute("UPDATE fm_installations SET status='active' WHERE id=?",[$onboardingSite]);
$check($call('account.summary')['onboarding']['step'] === 'ready','Ready link is unlocked after provisioning');
printf("%s regression checks passed (%d).\n", $argv[2], $checkCount);

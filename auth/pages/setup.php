<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../includes/access.php';
tdc_require_access();
tdc_require_permission('setup.view');
require_once __DIR__ . '/../includes/data-transfer.php';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

function setup_e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function setup_normalize_name(string $name): string { return trim((string) preg_replace('/\s+/u', ' ', $name)); }
function setup_assert_unique(PDO $pdo, string $table, string $column, string $name, int $ignoreId = 0, string $idColumn = ''): void
{
    $sql = "SELECT COUNT(*) FROM $table WHERE LOWER($column)=LOWER(?)";
    $args = [$name];
    if ($ignoreId && $idColumn !== '') { $sql .= " AND $idColumn<>?"; $args[] = $ignoreId; }
    $stmt = $pdo->prepare($sql); $stmt->execute($args);
    if ((int) $stmt->fetchColumn()) { throw new RuntimeException('A record with this name already exists.'); }
}
function setup_redirect(string $section, string $flag='saved'): never { header('Location: setup.php?section='.urlencode($section).'&'.$flag.'=1'); exit; }
function setup_slug(string $name): string
{
    $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
    return $slug !== '' ? substr($slug, 0, 90) : 'custom_role';
}
function setup_icon(string $name): string
{
    $paths=['overview'=>'<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>','building'=>'<path d="M3 21h18M5 21V5l7-3 7 3v16M9 9h2M9 13h2M9 17h2M15 9h1M15 13h1M15 17h1"/>','users'=>'<path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M8.5 11a4 4 0 100-8 4 4 0 000 8M20 8v6M17 11h6"/>','shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-5"/>','key'=>'<circle cx="8" cy="15" r="5"/><path d="M12 11l8-8M17 3h3v3M14 6l3 3"/>','clinical'=>'<path d="M6 3v5a6 6 0 0012 0V3M4 3h4M16 3h4M12 14v2a5 5 0 005 5h1"/><circle cx="20" cy="21" r="2"/>','lab'=>'<path d="M9 3h6M10 3v6l-5 8a2 2 0 001.7 3h10.6a2 2 0 001.7-3l-5-8V3M8 15h8"/>','pill'=>'<path d="M14 3a5 5 0 017 7L10 21a5 5 0 01-7-7L14 3zM8 9l7 7"/>','payment'=>'<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>','audit'=>'<path d="M9 4H4v16h16v-5M14 3h7v7M21 3l-9 9"/>'];
    $aliases = ['organization'=>'building','laboratory'=>'lab','pharmacy'=>'pill','financial'=>'payment','system'=>'overview','communication'=>'overview'];
    $name = $aliases[$name] ?? $name;
    return '<svg viewBox="0 0 24 24" aria-hidden="true">'.($paths[$name]??$paths['overview']).'</svg>';
}

const NAV_ITEMS = [
 ['href'=>'home.php','label'=>'Dashboard','icon'=>'<path d="M3 4a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm8-8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>'],
 ['href'=>'reception.php','label'=>'Reception','icon'=>'<path d="M10 2a1 1 0 011 1v1.06A6 6 0 0116 10v3l1.3 2.6a1 1 0 01-.9 1.4H3.6a1 1 0 01-.9-1.4L4 13v-3a6 6 0 015-5.94V3a1 1 0 011-1z"/>'],
 ['href'=>'doctors.php','label'=>'Doctors','icon'=>'<path d="M7 2a1 1 0 00-1 1v3a1 1 0 002 0V4h4v2a1 1 0 002 0V3a1 1 0 00-1-1H7zM5 9v3a5 5 0 0010 0V9"/>'],
 ['href'=>'patients.php','label'=>'Patients','icon'=>'<path d="M10 2a3 3 0 100 6 3 3 0 000-6zM4 18a6 6 0 0112 0"/>'],
 ['href'=>'laboratory.php','label'=>'Laboratory','icon'=>'<path d="M8 2h4M9 3v6l-5 6a2 2 0 001.6 3h8.8A2 2 0 0016 15l-5-6V3"/>'],
 ['href'=>'pharmacy.php','label'=>'Pharmacy','icon'=>'<path d="M14 3a4 4 0 00-6 0L3 8a4 4 0 006 6l5-5a4 4 0 000-6zM7 7l6 6"/>'],
 ['href'=>'accounting.php','label'=>'Accounting','icon'=>'<path d="M4 3h12a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4a1 1 0 011-1zm2 4h8M6 11h8M6 15h5"/>'],
 ['href'=>'reports.php','label'=>'Reports','icon'=>'<path d="M4 18v-5h3v5M9 18V8h3v10M14 18V3h3v15"/>'],
 ['href'=>'setup.php','label'=>'Setup','icon'=>'<path d="M8.3 2h3.4l.5 2a7 7 0 011.8 1l2-.6 1.7 3-1.5 1.4a7 7 0 010 2.2l1.5 1.4-1.7 3-2-.6a7 7 0 01-1.8 1l-.5 2H8.3l-.5-2a7 7 0 01-1.8-1l-2 .6-1.7-3L3.8 11a7 7 0 010-2.2L2.3 7.4l1.7-3 2 .6a7 7 0 011.8-1l.5-2zM10 13a3 3 0 100-6 3 3 0 000 6z"/>'],
];

$sections = ['organization','users','roles','permissions','clinical','laboratory','pharmacy','payment-methods','audit'];
$section = (string) ($_GET['section'] ?? '');
if ($section !== '' && !in_array($section, $sections, true)) $section = '';
$sectionPermissions = [
 'organization'=>'setup.organization.manage','users'=>'setup.users.manage','roles'=>'setup.roles.manage','permissions'=>'setup.permissions.manage',
 'clinical'=>'setup.clinical.manage','laboratory'=>'setup.laboratory.manage','pharmacy'=>'setup.pharmacy.manage','payment-methods'=>'setup.financial.manage','audit'=>'setup.system.manage',
];
if ($section !== '' && !tdc_can($sectionPermissions[$section])) tdc_forbidden();

$errors = [];
$notice = isset($_GET['saved']) ? 'Changes saved successfully.' : (isset($_GET['status']) ? 'Status updated successfully.' : '');
$validMethods = array_column(tdc_payment_methods($pdo), 'MethodName');

if (($_GET['download'] ?? '') === 'user-template') {
    tdc_require_permission('setup.users.manage');
    tdc_csv_download('user-import-example.csv',['legal_name','username','role_key','doctor_id','is_active','temporary_password'],[['Example User','example_user','receptionuser','','1','ChangeMe123!']]);
}
if (($_GET['download'] ?? '') === 'users') {
    tdc_require_permission('setup.users.manage');$rows=[];$stmt=$pdo->query('SELECT u.userlegalname,u.username,r.RoleKey,COALESCE(d.DoctorID,\'\') DoctorID,u.is_active,u.last_login_at FROM users u JOIN Roles r ON r.RoleID=u.role_id LEFT JOIN Doctors d ON d.UserID=u.id ORDER BY u.userlegalname');foreach($stmt->fetchAll() as $row)$rows[]=array_values($row);tdc_csv_download('users-'.date('Y-m-d').'.csv',['legal_name','username','role_key','doctor_id','is_active','last_login_at'],$rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['setup_action'] ?? '');
        try {
            if ($action === 'import_users') {
                tdc_require_permission('setup.users.manage');$rows=tdc_csv_upload_rows($_FILES['csv_file']??[],['legal_name','username','role_key','temporary_password']);if(!$rows)throw new RuntimeException('The CSV file contains no user rows.');$pdo->beginTransaction();$imported=0;
                foreach($rows as $index=>$row){$name=trim((string)($row['legal_name']??''));$username=trim((string)($row['username']??''));$roleKey=trim((string)($row['role_key']??''));$password=(string)($row['temporary_password']??'');$doctorId=(int)($row['doctor_id']??0);$active=(string)($row['is_active']??'1')==='0'?0:1;if(mb_strlen($name)<2||!preg_match('/^[a-zA-Z0-9_.]{3,100}$/',$username)||mb_strlen($password)<8)throw new RuntimeException('Row '.($index+2).': valid name, username and password of at least 8 characters are required.');$stmt=$pdo->prepare('SELECT RoleID FROM Roles WHERE RoleKey=? AND IsActive=1');$stmt->execute([$roleKey]);$roleId=(int)$stmt->fetchColumn();if(!$roleId)throw new RuntimeException('Row '.($index+2).": role '$roleKey' is not active.");$stmt=$pdo->prepare('SELECT COUNT(*) FROM users WHERE username=?');$stmt->execute([$username]);if((int)$stmt->fetchColumn())throw new RuntimeException('Row '.($index+2).": username '$username' already exists.");if($doctorId){$stmt=$pdo->prepare("SELECT COUNT(*) FROM RolePermissions rp JOIN Permissions p ON p.PermissionID=rp.PermissionID WHERE rp.RoleID=? AND p.PermissionKey='doctor.workspace'");$stmt->execute([$roleId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Row '.($index+2).': linked doctor accounts require Doctor Workspace permission.');}$pdo->prepare('INSERT INTO users (userlegalname,role,role_id,username,password,is_active,is_root) VALUES (?,?,?,?,?,?,0)')->execute([$name,$roleKey,$roleId,$username,password_hash($password,PASSWORD_DEFAULT),$active]);$userId=(int)$pdo->lastInsertId();if($doctorId){$stmt=$pdo->prepare('UPDATE Doctors SET UserID=? WHERE DoctorID=? AND UserID IS NULL');$stmt->execute([$userId,$doctorId]);if(!$stmt->rowCount())throw new RuntimeException('Row '.($index+2).': doctor profile is invalid or already linked.');}$imported++;}
                tdc_audit($pdo,'setup.users.imported','users',null,"Imported $imported user accounts.");$pdo->commit();setup_redirect('users');
            } elseif ($action === 'save_profile') {
                tdc_require_permission('setup.organization.manage');
                $profile = ['clinic_name'=>trim((string)($_POST['clinic_name']??'')),'phone'=>trim((string)($_POST['phone']??'')),'email'=>trim((string)($_POST['email']??'')),'address'=>trim((string)($_POST['address']??'')),'currency'=>trim((string)($_POST['currency']??'')),'timezone'=>trim((string)($_POST['timezone']??''))];
                if ($profile['clinic_name']==='') throw new RuntimeException('Clinic name is required.');
                if ($profile['email']!=='' && !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid clinic email address.');
                if ($profile['timezone']!=='' && !in_array($profile['timezone'], timezone_identifiers_list(), true)) throw new RuntimeException('Select a valid timezone.');
                $pdo->beginTransaction(); $stmt=$pdo->prepare('INSERT INTO ClinicSettings (SettingKey,SettingValue,UpdatedBy) VALUES (?,?,?) ON DUPLICATE KEY UPDATE SettingValue=VALUES(SettingValue),UpdatedBy=VALUES(UpdatedBy)');
                foreach($profile as $key=>$value)$stmt->execute([$key,$value,(int)$_SESSION['user_id']]);
                tdc_audit($pdo,'setup.profile.updated','ClinicSettings','clinic','Updated clinic profile.'); $pdo->commit(); setup_redirect('organization');
            } elseif ($action === 'save_master') {
                $type=(string)($_POST['master_type']??''); $map=['department'=>['Departments','DepartmentID','DepartmentName','setup.organization.manage'],'specialization'=>['Specializations','SpecializationID','SpecializationName','setup.clinical.manage']];
                if(!isset($map[$type]))throw new RuntimeException('Invalid setup record.'); [$table,$idColumn,$nameColumn,$permission]=$map[$type];tdc_require_permission($permission);
                $id=(int)($_POST['record_id']??0);$name=setup_normalize_name((string)($_POST['record_name']??''));$description=trim((string)($_POST['description']??''));$active=isset($_POST['is_active'])?1:0;
                if(mb_strlen($name)<2||mb_strlen($name)>120)throw new RuntimeException('Name must be 2-120 characters.');if(mb_strlen($description)>500)throw new RuntimeException('Description is too long.');setup_assert_unique($pdo,$table,$nameColumn,$name,$id,$idColumn);
                if($id){$stmt=$pdo->prepare("UPDATE $table SET $nameColumn=?,Description=?,IsActive=? WHERE $idColumn=?");$stmt->execute([$name,$description?:null,$active,$id]);}else{$stmt=$pdo->prepare("INSERT INTO $table ($nameColumn,Description,IsActive) VALUES (?,?,1)");$stmt->execute([$name,$description?:null]);$id=(int)$pdo->lastInsertId();}
                tdc_audit($pdo,'setup.master.saved',$table,(string)$id,"Saved $type: $name");setup_redirect($type==='department'?'organization':'clinical');
            } elseif ($action === 'save_user') {
                tdc_require_permission('setup.users.manage');
                $id=(int)($_POST['user_id']??0);$name=trim((string)($_POST['userlegalname']??''));$username=trim((string)($_POST['username']??''));$roleId=(int)($_POST['role_id']??0);$password=(string)($_POST['password']??'');$doctorId=(int)($_POST['DoctorID']??0);
                if(mb_strlen($name)<2||mb_strlen($name)>255)throw new RuntimeException('Full name must be 2-255 characters.');if(!preg_match('/^[a-zA-Z0-9_.]{3,100}$/',$username))throw new RuntimeException('Username must be 3-100 letters, numbers, dots or underscores.');
                $stmt=$pdo->prepare('SELECT RoleKey FROM Roles WHERE RoleID=? AND IsActive=1');$stmt->execute([$roleId]);$roleKey=$stmt->fetchColumn();if(!$roleKey)throw new RuntimeException('Select an active role.');
                $stmt=$pdo->prepare('SELECT COUNT(*) FROM users WHERE username=? AND id<>?');$stmt->execute([$username,$id]);if((int)$stmt->fetchColumn())throw new RuntimeException('That username is already in use.');if(!$id&&mb_strlen($password)<8)throw new RuntimeException('A password of at least 8 characters is required.');if($password!==''&&mb_strlen($password)<8)throw new RuntimeException('Password must be at least 8 characters.');
                if($id){$stmt=$pdo->prepare('SELECT is_root FROM users WHERE id=?');$stmt->execute([$id]);if((int)$stmt->fetchColumn()&&$roleKey!=='superuser')throw new RuntimeException('The protected root SuperAdmin role cannot be changed.');}
                $pdo->beginTransaction();
                if($id){$sql='UPDATE users SET userlegalname=?,username=?,role=?,role_id=?'.($password!==''?',password=?':'').' WHERE id=?';$args=[$name,$username,$roleKey,$roleId];if($password!=='')$args[]=password_hash($password,PASSWORD_DEFAULT);$args[]=$id;$pdo->prepare($sql)->execute($args);}else{$pdo->prepare('INSERT INTO users (userlegalname,role,role_id,username,password,is_active) VALUES (?,?,?,?,?,1)')->execute([$name,$roleKey,$roleId,$username,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$pdo->lastInsertId();}
                $pdo->prepare('UPDATE Doctors SET UserID=NULL WHERE UserID=?')->execute([$id]);
                if($doctorId){$stmt=$pdo->prepare("SELECT COUNT(*) FROM RolePermissions rp JOIN Permissions p ON p.PermissionID=rp.PermissionID WHERE rp.RoleID=? AND p.PermissionKey='doctor.workspace'");$stmt->execute([$roleId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Only a role with Doctor Workspace permission can be linked to a doctor.');$stmt=$pdo->prepare('UPDATE Doctors SET UserID=? WHERE DoctorID=? AND (UserID IS NULL OR UserID=?)');$stmt->execute([$id,$doctorId,$id]);if(!$stmt->rowCount())throw new RuntimeException('That doctor is linked to another account.');}
                tdc_audit($pdo,'setup.user.saved','users',(string)$id,"Saved user account: $username");$pdo->commit();setup_redirect('users');
            } elseif ($action === 'user_status') {
                tdc_require_permission('setup.users.manage');$id=(int)($_POST['user_id']??0);$active=(int)($_POST['is_active']??0);
                $stmt=$pdo->prepare('SELECT username,is_root FROM users WHERE id=?');$stmt->execute([$id]);$target=$stmt->fetch();if(!$target)throw new RuntimeException('User not found.');if((int)$target['is_root']&&!$active)throw new RuntimeException('The root SuperAdmin cannot be deactivated.');if($id===(int)$_SESSION['user_id']&&!$active)throw new RuntimeException('You cannot deactivate your current account.');
                $pdo->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$active,$id]);tdc_audit($pdo,'setup.user.status','users',(string)$id,($active?'Activated ':'Deactivated ').$target['username']);setup_redirect('users','status');
            } elseif ($action === 'save_role') {
                tdc_require_permission('setup.roles.manage');$id=(int)($_POST['role_id']??0);$name=trim((string)($_POST['role_name']??''));$description=trim((string)($_POST['description']??''));$copyId=(int)($_POST['copy_role_id']??0);$active=isset($_POST['is_active'])?1:0;
                if(mb_strlen($name)<2||mb_strlen($name)>100)throw new RuntimeException('Role name must be 2-100 characters.');if(mb_strlen($description)>500)throw new RuntimeException('Role description is too long.');
                if($id){$stmt=$pdo->prepare('SELECT IsProtected FROM Roles WHERE RoleID=?');$stmt->execute([$id]);$protected=(int)$stmt->fetchColumn();if($protected&&!$active)throw new RuntimeException('Protected system roles cannot be deactivated.');if(!$active){$stmt=$pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id=?');$stmt->execute([$id]);if((int)$stmt->fetchColumn())throw new RuntimeException('Reassign users before deactivating this role.');}$pdo->prepare('UPDATE Roles SET RoleName=?,Description=?,IsActive=? WHERE RoleID=?')->execute([$name,$description?:null,$active,$id]);}else{$key=setup_slug($name);$stmt=$pdo->prepare('SELECT COUNT(*) FROM Roles WHERE RoleKey=?');$stmt->execute([$key]);if((int)$stmt->fetchColumn())$key.='_'.substr(bin2hex(random_bytes(3)),0,6);$pdo->prepare('INSERT INTO Roles (RoleKey,RoleName,Description,IsSystem,IsProtected,IsActive) VALUES (?,?,?,0,0,1)')->execute([$key,$name,$description?:null]);$id=(int)$pdo->lastInsertId();if($copyId)$pdo->prepare('INSERT INTO RolePermissions (RoleID,PermissionID) SELECT ?,PermissionID FROM RolePermissions WHERE RoleID=?')->execute([$id,$copyId]);}
                tdc_audit($pdo,'setup.role.saved','Roles',(string)$id,"Saved role: $name");setup_redirect('roles');
            } elseif ($action === 'save_permissions') {
                tdc_require_permission('setup.permissions.manage');$roleId=(int)($_POST['role_id']??0);$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['permissions']??[])))));
                $stmt=$pdo->prepare('SELECT RoleName,RoleKey,IsProtected FROM Roles WHERE RoleID=?');$stmt->execute([$roleId]);$role=$stmt->fetch();if(!$role)throw new RuntimeException('Role not found.');if($role['RoleKey']==='superuser'||(int)$role['IsProtected']&&$role['RoleKey']==='superuser')throw new RuntimeException('SuperAdmin always has full access and cannot be restricted.');
                if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=$pdo->prepare("SELECT PermissionID,PermissionKey FROM Permissions WHERE PermissionID IN ($marks)");$stmt->execute($ids);$valid=$stmt->fetchAll();if(count($valid)!==count($ids))throw new RuntimeException('One or more permissions are invalid.');}else{$valid=[];}
                $keys=array_column($valid,'PermissionKey');$dependencies=['pharmacy.dispense'=>'pharmacy.prescriptions.view','lab_billing.payment'=>'lab_billing.view','patients.import'=>'patients.create','patients.export'=>'patients.view','doctors.import'=>'doctors.manage','doctors.export'=>'doctors.view','reports.export'=>'reports.view','setup.permissions.manage'=>'setup.view','setup.roles.manage'=>'setup.view','setup.users.manage'=>'setup.view'];foreach($dependencies as $child=>$parent)if(in_array($child,$keys,true)&&!in_array($parent,$keys,true))throw new RuntimeException("$child requires $parent.");
                $beforeStmt=$pdo->prepare('SELECT p.PermissionKey FROM RolePermissions rp JOIN Permissions p ON p.PermissionID=rp.PermissionID WHERE rp.RoleID=?');$beforeStmt->execute([$roleId]);$before=$beforeStmt->fetchAll(PDO::FETCH_COLUMN);
                $pdo->beginTransaction();$pdo->prepare('DELETE FROM RolePermissions WHERE RoleID=?')->execute([$roleId]);$insert=$pdo->prepare('INSERT INTO RolePermissions (RoleID,PermissionID) VALUES (?,?)');foreach($ids as $permissionId)$insert->execute([$roleId,$permissionId]);tdc_audit($pdo,'setup.permissions.updated','Roles',(string)$roleId,'Changed permissions for '.$role['RoleName'],['added'=>array_values(array_diff($keys,$before)),'removed'=>array_values(array_diff($before,$keys))]);$pdo->commit();setup_redirect('permissions');
            } elseif ($action === 'save_payment_method') {
                tdc_require_permission('setup.financial.manage');$id=(int)($_POST['method_id']??0);$name=setup_normalize_name((string)($_POST['method_name']??''));$description=trim((string)($_POST['description']??''));$active=isset($_POST['is_active'])?1:0;if(mb_strlen($name)<2||mb_strlen($name)>80)throw new RuntimeException('Payment method name must be 2-80 characters.');setup_assert_unique($pdo,'PaymentMethods','MethodName',$name,$id,'PaymentMethodID');if($id)$pdo->prepare('UPDATE PaymentMethods SET MethodName=?,Description=?,IsActive=? WHERE PaymentMethodID=?')->execute([$name,$description?:null,$active,$id]);else{$pdo->prepare('INSERT INTO PaymentMethods (MethodName,Description,IsActive,DisplayOrder) VALUES (?,?,1,100)')->execute([$name,$description?:null]);$id=(int)$pdo->lastInsertId();}tdc_audit($pdo,'setup.payment_method.saved','PaymentMethods',(string)$id,"Saved payment method: $name");setup_redirect('payment-methods');
            } elseif ($action === 'save_doctor_setup') {
                tdc_require_permission('setup.clinical.manage');$doctorId=(int)($_POST['doctor_id']??0);$fee=(string)($_POST['fee']??'');$specialty=setup_normalize_name((string)($_POST['specialty']??''));$userId=(int)($_POST['doctor_user_id']??0);if(!$doctorId||!is_numeric($fee)||(float)$fee<0)throw new RuntimeException('Select a doctor and enter a valid fee.');if($userId){$stmt=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN RolePermissions rp ON rp.RoleID=u.role_id JOIN Permissions p ON p.PermissionID=rp.PermissionID WHERE u.id=? AND u.is_active=1 AND p.PermissionKey='doctor.workspace'");$stmt->execute([$userId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Select an active account with Doctor Workspace permission.');$stmt=$pdo->prepare('SELECT COUNT(*) FROM Doctors WHERE UserID=? AND DoctorID<>?');$stmt->execute([$userId,$doctorId]);if((int)$stmt->fetchColumn())throw new RuntimeException('That account is already linked to another doctor.');}$pdo->prepare('UPDATE Doctors SET ConsultationFee=?,Specialty=?,UserID=? WHERE DoctorID=?')->execute([round((float)$fee,2),$specialty?:null,$userId?:null,$doctorId]);tdc_audit($pdo,'setup.doctor.updated','Doctors',(string)$doctorId,'Updated doctor configuration.');setup_redirect('clinical');
            } elseif ($action === 'save_lab_service') {
                tdc_require_permission('setup.laboratory.manage');$id=(int)($_POST['service_id']??0);$name=setup_normalize_name((string)($_POST['service_name']??''));$category=trim((string)($_POST['category']??''));$description=trim((string)($_POST['description']??''));$price=(string)($_POST['price']??'');$available=isset($_POST['is_available'])?1:0;$active=isset($_POST['is_active'])?1:0;if(mb_strlen($name)<2||mb_strlen($name)>150||!is_numeric($price)||(float)$price<0)throw new RuntimeException('Enter a valid service name and non-negative price.');setup_assert_unique($pdo,'LabServices','ServiceName',$name,$id,'ServiceID');if($id)$pdo->prepare('UPDATE LabServices SET ServiceName=?,Category=?,Description=?,Price=?,IsAvailable=?,IsActive=? WHERE ServiceID=?')->execute([$name,$category?:null,$description?:null,round((float)$price,2),$available,$active,$id]);else{$pdo->prepare('INSERT INTO LabServices (ServiceName,Category,Description,Price,IsAvailable,IsActive) VALUES (?,?,?,?,1,1)')->execute([$name,$category?:null,$description?:null,round((float)$price,2)]);$id=(int)$pdo->lastInsertId();}tdc_audit($pdo,'setup.lab_service.saved','LabServices',(string)$id,"Saved laboratory service: $name");setup_redirect('laboratory');
            } else throw new RuntimeException('Unsupported Setup action.');
        } catch (RuntimeException $e) { if($pdo->inTransaction())$pdo->rollBack();$errors[]=$e->getMessage(); }
        catch (PDOException $e) { if($pdo->inTransaction())$pdo->rollBack();error_log('[SETUP] '.$e->getMessage());$errors[]=$e->getCode()==='23000'?'That name or username already exists.':'The change could not be saved.'; }
    }
    $_SESSION['csrf_token']=bin2hex(random_bytes(32));
}

$roles=$pdo->query('SELECT r.*,COUNT(DISTINCT u.id) UserCount,COUNT(DISTINCT rp.PermissionID) PermissionCount FROM Roles r LEFT JOIN users u ON u.role_id=r.RoleID LEFT JOIN RolePermissions rp ON rp.RoleID=r.RoleID GROUP BY r.RoleID ORDER BY r.IsSystem DESC,r.RoleName')->fetchAll();
$users=$pdo->query('SELECT u.id,u.userlegalname,u.username,u.role_id,u.is_active,u.is_root,u.last_login_at,u.created_at,r.RoleName,r.RoleKey,d.DoctorID,d.DoctorName FROM users u LEFT JOIN Roles r ON r.RoleID=u.role_id LEFT JOIN Doctors d ON d.UserID=u.id ORDER BY u.is_root DESC,u.userlegalname')->fetchAll();
$doctorAccounts=$pdo->query("SELECT DISTINCT u.id,u.userlegalname,u.username FROM users u JOIN RolePermissions rp ON rp.RoleID=u.role_id JOIN Permissions p ON p.PermissionID=rp.PermissionID WHERE p.PermissionKey='doctor.workspace' AND u.is_active=1 ORDER BY u.userlegalname")->fetchAll();
$doctors=$pdo->query('SELECT d.*,u.username FROM Doctors d LEFT JOIN users u ON u.id=d.UserID ORDER BY d.DoctorName')->fetchAll();
$permissions=$pdo->query('SELECT * FROM Permissions ORDER BY ModuleName,ResourceName,ActionName')->fetchAll();
$selectedRoleId=(int)($_GET['role_id']??0);if(!$selectedRoleId&&$roles)$selectedRoleId=(int)$roles[0]['RoleID'];
$assignedPermissionIds=[];if($selectedRoleId){$stmt=$pdo->prepare('SELECT PermissionID FROM RolePermissions WHERE RoleID=?');$stmt->execute([$selectedRoleId]);$assignedPermissionIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));}
$selectedRole=null;foreach($roles as $role)if((int)$role['RoleID']===$selectedRoleId)$selectedRole=$role;
$profile=[];foreach($pdo->query('SELECT SettingKey,SettingValue FROM ClinicSettings')->fetchAll() as $setting)$profile[$setting['SettingKey']]=$setting['SettingValue'];
$departments=$pdo->query('SELECT * FROM Departments ORDER BY IsActive DESC,DepartmentName')->fetchAll();
$specializations=$pdo->query('SELECT * FROM Specializations ORDER BY IsActive DESC,SpecializationName')->fetchAll();
$labServices=$pdo->query('SELECT * FROM LabServices ORDER BY IsActive DESC,Category,ServiceName')->fetchAll();
$paymentMethods=tdc_payment_methods($pdo,true);
$inventorySummary=$pdo->query('SELECT Category,COUNT(*) ItemCount,COUNT(DISTINCT SalesUnit) UnitCount,SUM(QuantityInStock<=ReorderLevel) LowStock FROM Inventory GROUP BY Category ORDER BY Category')->fetchAll();
$auditSearch=trim((string)($_GET['audit_search']??''));
$auditModule=trim((string)($_GET['audit_module']??''));
$auditFrom=trim((string)($_GET['audit_from']??''));
$auditTo=trim((string)($_GET['audit_to']??''));
try{$auditModules=$pdo->query('SELECT DISTINCT EntityType FROM AuditLog WHERE EntityType IS NOT NULL ORDER BY EntityType')->fetchAll(PDO::FETCH_COLUMN);}catch(PDOException $e){$auditModules=[];}
$auditWhere=[];$auditParams=[];
if($auditSearch!==''){$like='%'.$auditSearch.'%';$auditWhere[]='(a.Summary LIKE ? OR a.EventType LIKE ? OR a.EntityType LIKE ? OR a.EntityID LIKE ?)';array_push($auditParams,$like,$like,$like,$like);}
if($auditModule!==''){$auditWhere[]='a.EntityType = ?';$auditParams[]=$auditModule;}
if($auditFrom!==''){$auditWhere[]='a.CreatedAt >= ?';$auditParams[]=$auditFrom.' 00:00:00';}
if($auditTo!==''){$auditWhere[]='a.CreatedAt < ?';$auditParams[]=date('Y-m-d',strtotime($auditTo.' +1 day')).' 00:00:00';}
$auditSql='SELECT a.*,u.userlegalname FROM AuditLog a LEFT JOIN users u ON u.id=a.ActorUserID'.($auditWhere?' WHERE '.implode(' AND ',$auditWhere):'').' ORDER BY a.CreatedAt DESC LIMIT 200';
try{$auditStmt=$pdo->prepare($auditSql);$auditStmt->execute($auditParams);$auditRows=$auditStmt->fetchAll();}catch(PDOException $e){error_log('[SETUP] audit: '.$e->getMessage());$auditRows=[];}

$legalName=(string)$_SESSION['userlegalname'];$displayName=$legalName;$avatarLetters=strtoupper(substr($displayName,0,2));$csrfToken=(string)$_SESSION['csrf_token'];$currentPage='setup.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup | Tarey Derma Clinic</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet"><link rel="stylesheet" href="../assets/clinic.css"><script src="../assets/clinic.js" defer></script></head><body>
<header class="app-header" id="topnav"><div class="utility-bar"><a class="brand-chip" href="home.php"><img src="../uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic"></a><div class="utility-right"><div class="nav-item" data-menu="notifications"><button type="button" class="icon-btn" aria-label="Notifications"><svg viewBox="0 0 24 24"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z"/><path d="M9.5 21a2.5 2.5 0 005 0"/></svg><span class="badge"></span></button><div class="dropdown-menu notif-menu"><?php require __DIR__.'/../includes/notifications.php'; ?></div></div><?php require __DIR__.'/../includes/profile.php'; ?></div></div><nav class="menu-bar"><ul class="nav-items"><?php foreach(tdc_navigation(NAV_ITEMS) as $item): ?><li class="nav-item<?= $item['href']===$currentPage?' active':'' ?>"><a class="nav-link" href="<?= setup_e($item['href']) ?>"><svg viewBox="0 0 20 20"><?= $item['icon'] ?></svg><span><?= setup_e($item['label']) ?></span></a></li><?php endforeach; ?></ul></nav></header>
<main class="page-body setup-page"><div class="welcome-eyebrow">System Administration</div><div class="setup-title-row"><div><h1 class="welcome-title"><?= $section===''?'Setup':setup_e(ucwords(str_replace('-',' ',$section))) ?></h1><p class="welcome-sub"><?= $section===''?'System Configuration':'Manage this configuration using live system data.' ?></p></div></div>
<?php $setupNav=[['','Overview','overview'],['organization','Organization','building'],['users','Users','users'],['roles','Roles','shield'],['permissions','Permissions','key'],['clinical','Clinical','clinical'],['laboratory','Laboratory','lab'],['pharmacy','Pharmacy','pill'],['payment-methods','Financial','payment'],['audit','Audit','audit']];?><nav class="setup-section-nav" aria-label="Setup sections"><?php foreach($setupNav as [$target,$label,$icon]):if($target!==''&&!tdc_can($sectionPermissions[$target]))continue;?><a href="setup.php<?=$target!==''?'?section='.urlencode($target):''?>" class="<?=$section===$target?'active':''?>"><?=setup_icon($icon)?><span><?=setup_e($label)?></span></a><?php endforeach;?></nav>
<?php if($notice): ?><div class="setup-notice" role="status"><?= setup_e($notice) ?></div><?php endif; ?><?php if($errors): ?><div class="error-msg" role="alert"><?= setup_e(implode(' ',$errors)) ?></div><?php endif; ?>

<?php if($section===''): ?>
<p class="setup-intro">Manage clinic structure, users, access control, clinical master data, financial configuration and system preferences.</p>
<?php $groups=[
 'Organization'=>[['organization','Clinic Profile & Departments','Clinic identity, contact details and department master data.','building']],
 'Users & Access'=>[['users','Users','Accounts, role assignment, status and doctor links.','users'],['roles','Roles','System and custom organizational roles.','shield'],['permissions','Permissions','Business-action access for every role.','key']],
 'Clinical Setup'=>[['clinical','Doctors & Specializations','Doctor accounts, consultation fees and reusable specialties.','clinical']],
 'Laboratory Setup'=>[['laboratory','Test Catalogue','Tests, categories, prices and availability.','lab']],
 'Pharmacy Setup'=>[['pharmacy','Medicine Master Data','Inventory categories, units and reorder configuration.','pill']],
 'Financial Setup'=>[['payment-methods','Payment Methods','Methods available to cashier workflows.','payment']],
 'System'=>[['audit','Audit Log','Administrative configuration and access changes.','audit']],
]; foreach($groups as $group=>$cards): ?><section class="setup-group"><h2><?= setup_e($group) ?></h2><div class="setup-card-grid"><?php foreach($cards as [$target,$title,$description,$icon]): if(!tdc_can($sectionPermissions[$target]))continue; ?><a href="setup.php?section=<?= setup_e($target) ?>" class="setup-module-card"><span class="setup-module-icon"><?=setup_icon($icon)?></span><span><strong><?= setup_e($title) ?></strong><small><?= setup_e($description) ?></small></span><svg class="setup-card-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a><?php endforeach; ?></div></section><?php endforeach; ?>

<?php elseif($section==='organization'): ?>
<div class="setup-two-column">
<section class="setup-form-panel">
<div class="workspace-panel-heading"><div><h2>Clinic Profile</h2><p>Identity and contact details used across the clinic.</p></div></div>
<form method="post" class="setup-panel-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_profile">
<div class="setup-form-grid">
<div class="form-group"><label for="clinic_name">Clinic Name *</label><input id="clinic_name" name="clinic_name" required value="<?=setup_e($profile['clinic_name']??'Tarey Derma Clinic')?>"></div>
<div class="form-group"><label for="clinic_phone">Phone</label><input id="clinic_phone" type="tel" name="phone" value="<?=setup_e($profile['phone']??'')?>"></div>
<div class="form-group"><label for="clinic_email">Email</label><input id="clinic_email" type="email" name="email" value="<?=setup_e($profile['email']??'')?>"></div>
<div class="form-group"><label for="clinic_currency">Currency</label><input id="clinic_currency" name="currency" maxlength="10" value="<?=setup_e($profile['currency']??'USD')?>"></div>
<div class="form-group"><label for="clinic_timezone">Timezone</label><select id="clinic_timezone" name="timezone"><option value="Africa/Mogadishu" <?=($profile['timezone']??'Africa/Mogadishu')==='Africa/Mogadishu'?'selected':''?>>Africa/Mogadishu</option><option value="UTC" <?=($profile['timezone']??'')==='UTC'?'selected':''?>>UTC</option></select></div>
<div class="form-group setup-span-2"><label for="clinic_address">Address</label><textarea id="clinic_address" name="address" rows="3"><?=setup_e($profile['address']??'')?></textarea></div>
</div>
<div class="setup-form-actions"><button class="btn btn-primary">Save Changes</button></div>
</form>
</section>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Departments</h2><p>Reusable clinic departments; deactivate records that are used historically.</p></div><button type="button" class="btn btn-primary" data-add-master data-modal="departmentModal" data-label="Department">Add Department</button></div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="departments-table" placeholder="Search departments..."></div></div>
<?php if(!$departments):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('organization')?></span><h3>No departments configured</h3><p>Create departments to organize doctors and clinic staff.</p><button type="button" class="btn btn-primary" data-add-master data-modal="departmentModal" data-label="Department">Add Department</button></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="departments-table"><thead><tr><th>Department</th><th>Description</th><th>Status</th><th class="cell-actions">Actions</th></tr></thead><tbody>
<?php foreach($departments as $item):?>
<tr><td><strong><?=setup_e($item['DepartmentName'])?></strong></td><td><?=setup_e($item['Description']?:'—')?></td><td><span class="status-badge<?=$item['IsActive']?'':' danger'?>"><?=$item['IsActive']?'Active':'Inactive'?></span></td><td class="cell-actions"><button type="button" class="btn btn-secondary btn-sm" data-edit-master='<?=setup_e(json_encode(['id'=>$item['DepartmentID'],'name'=>$item['DepartmentName'],'description'=>$item['Description'],'active'=>$item['IsActive'],'modal'=>'departmentModal','label'=>'Department']))?>'>Edit</button></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
</div>
<div class="modal-overlay" id="departmentModal"><div class="modal-box"><div class="modal-head"><div><h3 data-modal-title>Add Department</h3><p>Create a reusable clinic department.</p></div><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<form method="post" id="departmentForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_master"><input type="hidden" name="master_type" value="department"><input type="hidden" name="record_id">
<div class="form-group"><label>Department Name *</label><input name="record_name" required minlength="2" maxlength="120"></div>
<div class="form-group"><label>Description</label><textarea name="description" rows="3" maxlength="500"></textarea></div>
<label class="check-control"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Save Department</button></div></form></div></div>

<?php elseif($section==='users'): ?>
<div class="setup-page-command-bar"><div class="table-filter"><input type="search" data-table-filter="setup-users-table" placeholder="Filter users by name, username or role..."></div><div class="table-command-bar"><button type="button" class="btn btn-secondary" id="importUsersBtn">Import CSV</button><a class="btn btn-secondary" href="setup.php?section=users&amp;download=user-template">Download CSV Template</a><a class="btn btn-secondary" href="setup.php?section=users&amp;download=users">Export CSV</a><button type="button" class="btn btn-secondary" onclick="window.print()">Export PDF</button><button type="button" class="btn btn-primary" id="addSetupUserBtn">Add User</button></div></div>
<div class="modal-overlay" id="importUsersModal"><div class="modal-box"><div class="modal-head"><h3>Import Users</h3><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div><form method="post" enctype="multipart/form-data"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="import_users"><section class="form-section"><div class="form-section-heading"><span><strong>CSV File</strong><span>Passwords are accepted only for creating imported accounts and are never exported.</span></span></div><div class="form-group"><label>Select CSV</label><input type="file" name="csv_file" accept=".csv,text/csv" required></div></section><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Import Users</button></div></div></form></div></div>
<div class="modal-overlay" id="userSetupModal"><div class="modal-box patient-modal"><div class="modal-head"><h3 id="userSetupTitle">Add User</h3><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_user"><input type="hidden" name="user_id" id="su_user_id"><section class="form-section"><div class="form-section-heading"><span><strong>Account Identity</strong><span>Legal name and unique sign-in username.</span></span></div><div class="form-row"><div class="form-group"><label>Legal Name</label><input id="su_name" name="userlegalname" required></div><div class="form-group"><label>Username</label><input id="su_username" name="username" required autocomplete="off"></div></div></section><section class="form-section"><div class="form-section-heading"><span><strong>Access & Link</strong><span>Assign one live role and an optional doctor profile.</span></span></div><div class="form-row"><div class="form-group"><label>Role</label><select id="su_role" name="role_id" required><option value="">Select role</option><?php foreach($roles as $role):if(!$role['IsActive'])continue;?><option value="<?=$role['RoleID']?>"><?=setup_e($role['RoleName'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Doctor Profile (Optional)</label><select id="su_doctor" name="DoctorID"><option value="">Not linked</option><?php foreach($doctors as $doctor):?><option value="<?=$doctor['DoctorID']?>"><?=setup_e($doctor['DoctorName'])?><?=$doctor['UserID']?' · linked':''?></option><?php endforeach;?></select></div></div><div class="form-group"><label id="su_password_label">Temporary Password</label><input id="su_password" type="password" name="password" minlength="8" autocomplete="new-password"><div class="modal-hint">Leave blank while editing to keep the current password.</div></div></section><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Save User</button></div></div></form></div></div>
<section class="setup-table-panel"><div class="workspace-panel-heading"><div><h2>System Users</h2><p>Role, account status and staff linkage.</p></div></div><div class="data-table-wrap"><table class="data-table" id="setup-users-table"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Linked Doctor</th><th>Status</th><th>Last Login</th><th class="cell-actions">Actions</th></tr></thead><tbody><?php if(!$users):?><tr><td colspan="7"><div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('users')?></span><h3>No users found</h3><p>Add a user account to grant access to the clinic system.</p></div></td></tr><?php else:foreach($users as $user):?><tr><td><?=setup_e($user['userlegalname'])?> <?=$user['is_root']?'<span class="status-badge">Root</span>':''?></td><td>@<?=setup_e($user['username'])?></td><td><?=setup_e($user['RoleName']??$user['RoleKey'])?></td><td><?=$user['DoctorName']?setup_e($user['DoctorName']):'<span class="cell-sub">Not linked</span>'?></td><td><span class="status-badge<?=$user['is_active']?'':' danger'?>"><?=$user['is_active']?'Active':'Inactive'?></span></td><td><?=setup_e($user['last_login_at']?date('d M Y H:i',strtotime($user['last_login_at'])):'Never')?></td><td class="cell-actions"><div class="setup-actions"><?php if(!$user['is_root']):?><button type="button" class="btn btn-secondary btn-sm" data-edit-user='<?=setup_e(json_encode(['id'=>$user['id'],'userlegalname'=>$user['userlegalname'],'username'=>$user['username'],'role_id'=>$user['role_id'],'DoctorID'=>$user['DoctorID']]))?>'>Edit</button><?php endif;?><?php if(!$user['is_root']&&(int)$user['id']!==(int)$_SESSION['user_id']):?><form method="post" class="setup-inline-action"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="user_status"><input type="hidden" name="user_id" value="<?=$user['id']?>"><input type="hidden" name="is_active" value="<?=$user['is_active']?0:1?>"><button type="button" class="btn btn-sm <?=$user['is_active']?'btn-danger':'btn-secondary'?>" data-toggle-user="<?=$user['is_active']?1:0?>"><?=$user['is_active']?'Deactivate':'Activate'?></button></form><?php elseif($user['is_root']):?><span class="status-badge">Protected</span><?php else:?><span class="cell-sub">Current account</span><?php endif;?></div></td></tr><?php endforeach;endif;?></tbody></table></div></section>

<?php elseif($section==='roles'): ?>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Roles</h2><p>System roles are protected; custom roles can be configured.</p></div><button type="button" class="btn btn-primary" data-add-role>Add Role</button></div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="roles-table" placeholder="Search roles..."></div></div>
<?php if(!$roles):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('shield')?></span><h3>No roles configured</h3><p>Create a role to group permissions for clinic staff.</p><button type="button" class="btn btn-primary" data-add-role>Add Role</button></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="roles-table"><thead><tr><th>Role</th><th>Description</th><th>Users</th><th>Permissions</th><th>Type</th><th>Status</th><th class="cell-actions">Actions</th></tr></thead><tbody>
<?php foreach($roles as $role):?>
<tr><td><strong><?=setup_e($role['RoleName'])?></strong></td><td><?=setup_e($role['Description']?:'&mdash;')?></td><td><?=(int)$role['UserCount']?></td><td><?=(int)$role['PermissionCount']?></td><td><span class="status-badge<?=$role['IsSystem']?' info':''?>"><?=$role['IsSystem']?'System':'Custom'?></span></td><td><span class="status-badge<?=$role['IsActive']?'':' danger'?>"><?=$role['IsActive']?'Active':'Inactive'?></span></td><td class="cell-actions"><div class="setup-actions"><a class="btn btn-secondary btn-sm" href="setup.php?section=permissions&amp;role_id=<?=$role['RoleID']?>">Manage Permissions</a><?php if($role['IsSystem']):?><span class="status-badge">Protected</span><?php endif;?></div></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<div class="modal-overlay" id="roleModal"><div class="modal-box"><div class="modal-head"><div><h3 data-modal-title>Add Role</h3><p>Custom roles start empty or copy an existing role.</p></div><button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button></div>
<form method="post" id="roleForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_role"><input type="hidden" name="is_active" value="1">
<div class="form-group"><label for="role_name">Role Name *</label><input id="role_name" name="role_name" required minlength="2" maxlength="80"></div>
<div class="form-group"><label for="role_description">Description</label><textarea id="role_description" name="description" rows="3" maxlength="500"></textarea></div>
<div class="form-group"><label for="role_copy">Copy Permissions From</label><select id="role_copy" name="copy_role_id"><option value="">Start Empty</option><?php foreach($roles as $role):?><option value="<?=$role['RoleID']?>"><?=setup_e($role['RoleName'])?></option><?php endforeach;?></select></div>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Create Role</button></div></form></div></div>

<?php elseif($section==='permissions'): ?>
<form method="get" class="permission-role-picker"><input type="hidden" name="section" value="permissions"><label for="role-picker">Role</label><select id="role-picker" name="role_id" onchange="this.form.submit()"><?php foreach($roles as $role):?><option value="<?=$role['RoleID']?>" <?=$selectedRoleId===$role['RoleID']?'selected':''?>><?=setup_e($role['RoleName'])?></option><?php endforeach;?></select></form>
<?php if($selectedRole):?><section class="role-summary"><div><span class="setup-module-icon"><?=setup_e(strtoupper(substr($selectedRole['RoleName'],0,1)))?></span><div><h2><?=setup_e($selectedRole['RoleName'])?></h2><p><?=setup_e($selectedRole['Description'])?></p></div></div><div><strong><?=$selectedRole['UserCount']?></strong><span>Users</span></div><div><strong><?=$selectedRole['PermissionCount']?></strong><span>Permissions</span></div><span class="status-badge"><?=$selectedRole['IsSystem']?'System Role':'Custom Role'?></span></section><?php endif;?>
<?php if($selectedRole&&$selectedRole['RoleKey']==='superuser'):?><div class="setup-notice">SuperAdmin is protected and automatically retains all system permissions.</div><?php else:?><form method="post" id="permission-form"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_permissions"><input type="hidden" name="role_id" value="<?=$selectedRoleId?>"><div class="permission-toolbar"><input type="search" id="permission-search" placeholder="Search permissions..."><span id="permission-count"></span><button type="button" class="btn btn-secondary" id="permission-clear">Clear All</button><button class="btn btn-primary">Save Changes</button></div><?php $grouped=[];foreach($permissions as $permission)$grouped[$permission['ModuleName']][]=$permission;foreach($grouped as $module=>$items):?><section class="permission-group" data-permission-group><div class="workspace-panel-heading"><div><h2><?=setup_e($module)?></h2><p>Business actions available in this module.</p></div><label class="check-control"><input type="checkbox" data-select-module><span>Select All</span></label></div><div class="permission-grid"><?php foreach($items as $permission):?><label class="permission-item" data-search="<?=setup_e(strtolower($permission['PermissionKey'].' '.$permission['ResourceName'].' '.$permission['Description']))?>"><input type="checkbox" name="permissions[]" value="<?=$permission['PermissionID']?>" <?=in_array((int)$permission['PermissionID'],$assignedPermissionIds,true)?'checked':''?>><span><strong><?=setup_e($permission['ResourceName'])?></strong><small><?=setup_e($permission['Description'])?></small><code><?=setup_e($permission['PermissionKey'])?></code></span></label><?php endforeach;?></div></section><?php endforeach;?></form><?php endif;?>

<?php elseif($section==='clinical'): ?>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Doctors</h2><p>Consultation fees, specialization and linked sign-in accounts.</p></div></div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="doctors-table" placeholder="Search doctors..."></div></div>
<?php if(!$doctors):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('clinical')?></span><h3>No doctors configured</h3><p>Add doctor profiles before configuring consultation fees and schedules.</p></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="doctors-table"><thead><tr><th>Doctor</th><th>Specialization</th><th>Consultation Fee</th><th>User Account</th><th>Status</th><th class="cell-actions">Actions</th></tr></thead><tbody>
<?php foreach($doctors as $doctor):?>
<tr><td><strong><?=setup_e($doctor['DoctorName'])?></strong></td><td><?=$doctor['Specialty']?setup_e($doctor['Specialty']):'<span class="cell-sub">Not set</span>'?></td><td><?=setup_e(number_format((float)$doctor['ConsultationFee'],2))?></td><td><?=$doctor['UserID']&&$doctor['username']?'@'.setup_e($doctor['username']):'<span class="status-badge warn">Not linked</span>'?></td><td><span class="status-badge<?=$doctor['UserID']?'':' warn'?>"><?=$doctor['UserID']?'Linked':'Not linked'?></span></td><td class="cell-actions"><button type="button" class="btn btn-secondary btn-sm" data-edit-doctor='<?=setup_e(json_encode(['id'=>$doctor['DoctorID'],'name'=>$doctor['DoctorName'],'fee'=>$doctor['ConsultationFee'],'specialty'=>$doctor['Specialty'],'user'=>$doctor['UserID']]))?>'>Edit</button></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Specializations</h2><p>Reusable values loaded by doctor configuration.</p></div><button type="button" class="btn btn-primary" data-add-master data-modal="specializationModal" data-label="Specialization">Add Specialization</button></div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="specializations-table" placeholder="Search specializations..."></div></div>
<?php if(!$specializations):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('clinical')?></span><h3>No specializations configured</h3><p>Add specializations to reuse them across doctor profiles.</p><button type="button" class="btn btn-primary" data-add-master data-modal="specializationModal" data-label="Specialization">Add Specialization</button></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="specializations-table"><thead><tr><th>Specialization</th><th>Description</th><th>Status</th><th class="cell-actions">Actions</th></tr></thead><tbody>
<?php foreach($specializations as $item):?>
<tr><td><strong><?=setup_e($item['SpecializationName'])?></strong></td><td><?=setup_e($item['Description']?:'—')?></td><td><span class="status-badge<?=$item['IsActive']?'':' danger'?>"><?=$item['IsActive']?'Active':'Inactive'?></span></td><td class="cell-actions"><button type="button" class="btn btn-secondary btn-sm" data-edit-master='<?=setup_e(json_encode(['id'=>$item['SpecializationID'],'name'=>$item['SpecializationName'],'description'=>$item['Description'],'active'=>$item['IsActive'],'modal'=>'specializationModal','label'=>'Specialization']))?>'>Edit</button></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<div class="modal-overlay" id="doctorModal"><div class="modal-box"><div class="modal-head"><div><h3 data-modal-title>Edit Doctor</h3><p>Consultation fee, specialization and account linkage.</p></div><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<form method="post" id="doctorForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_doctor_setup"><input type="hidden" name="doctor_id">
<div class="form-group"><label>Doctor</label><input name="doctor_name_display" readonly></div>
<div class="form-row"><div class="form-group"><label>Consultation Fee *</label><input type="number" min="0" step=".01" name="fee" required></div><div class="form-group"><label>Specialization</label><select name="specialty"><option value="">Not set</option><?php foreach($specializations as $specialty):?><option value="<?=setup_e($specialty['SpecializationName'])?>"><?=setup_e($specialty['SpecializationName'])?></option><?php endforeach;?></select></div></div>
<div class="form-group"><label>User Account</label><select name="doctor_user_id"><option value="">Not linked</option><?php foreach($doctorAccounts as $account):?><option value="<?=$account['id']?>">@<?=setup_e($account['username'])?></option><?php endforeach;?></select></div>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Save Doctor</button></div></form></div></div>
<div class="modal-overlay" id="specializationModal"><div class="modal-box"><div class="modal-head"><div><h3 data-modal-title>Add Specialization</h3><p>Reusable clinical specialization.</p></div><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<form method="post" id="specializationForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_master"><input type="hidden" name="master_type" value="specialization"><input type="hidden" name="record_id">
<div class="form-group"><label>Specialization *</label><input name="record_name" required minlength="2" maxlength="120"></div>
<div class="form-group"><label>Description</label><textarea name="description" rows="3" maxlength="500"></textarea></div>
<label class="check-control"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Save Specialization</button></div></form></div></div>

<?php elseif($section==='laboratory'): ?>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Laboratory Test Catalogue</h2><p>Single source of truth for test identity, price and availability.</p></div><button type="button" class="btn btn-primary" data-add-lab>Add Test</button></div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="lab-table" placeholder="Search tests..."></div></div>
<?php if(!$labServices):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('laboratory')?></span><h3>No laboratory tests configured</h3><p>Add tests to make them available for laboratory requests and billing.</p><button type="button" class="btn btn-primary" data-add-lab>Add Test</button></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="lab-table"><thead><tr><th>Test</th><th>Category</th><th>Description</th><th>Price</th><th>Availability</th><th>Status</th><th class="cell-actions">Actions</th></tr></thead><tbody>
<?php foreach($labServices as $service):?>
<tr><td><strong><?=setup_e($service['ServiceName'])?></strong></td><td><?=setup_e($service['Category']?:'—')?></td><td><?=setup_e($service['Description']?:'—')?></td><td><?=setup_e(number_format((float)$service['Price'],2))?></td><td><span class="status-badge<?=$service['IsAvailable']?'':' warn'?>"><?=$service['IsAvailable']?'In-house':'Unavailable'?></span></td><td><span class="status-badge<?=$service['IsActive']?'':' danger'?>"><?=$service['IsActive']?'Active':'Inactive'?></span></td><td class="cell-actions"><button type="button" class="btn btn-secondary btn-sm" data-edit-lab='<?=setup_e(json_encode(['id'=>$service['ServiceID'],'name'=>$service['ServiceName'],'category'=>$service['Category'],'description'=>$service['Description'],'price'=>$service['Price'],'available'=>$service['IsAvailable'],'active'=>$service['IsActive']]))?>'>Edit</button></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<div class="modal-overlay" id="labModal"><div class="modal-box patient-modal"><div class="modal-head"><div><h3 data-modal-title>Add Laboratory Test</h3><p>Test identity, pricing and availability.</p></div><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<form method="post" id="labForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_lab_service"><input type="hidden" name="service_id">
<div class="form-row"><div class="form-group"><label>Test Name *</label><input name="service_name" required minlength="2" maxlength="150"></div><div class="form-group"><label>Category</label><input name="category" maxlength="120"></div></div>
<div class="form-group"><label>Description</label><textarea name="description" rows="3" maxlength="500"></textarea></div>
<div class="form-row"><div class="form-group"><label>Price *</label><input type="number" name="price" min="0" step=".01" required></div><div class="form-group"><label>Availability</label><select name="is_available"><option value="1">In-house</option><option value="0">Unavailable</option></select></div></div>
<label class="check-control"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Save Test</button></div></form></div></div>

<?php elseif($section==='pharmacy'): ?>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Pharmacy Master Data</h2><p>Categories, units and reorder status derived from inventory. Operational stock movement stays in Pharmacy.</p></div><a class="btn btn-primary" href="pharmacy.php?section=inventory">Manage Inventory</a></div>
<div class="setup-notice" role="status">Setup manages master data only. Stock In, Stock Out, dispensing and sales remain in the operational Pharmacy module.</div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="pharmacy-table" placeholder="Search categories..."></div></div>
<?php if(!$inventorySummary):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('pharmacy')?></span><h3>No medicine master data configured</h3><p>Add medicines in the operational Pharmacy module; their categories and units appear here.</p><a class="btn btn-primary" href="pharmacy.php?section=inventory">Open Pharmacy Inventory</a></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="pharmacy-table"><thead><tr><th>Category</th><th>Medicines</th><th>Units Used</th><th>Low Stock</th></tr></thead><tbody>
<?php foreach($inventorySummary as $row):?>
<tr><td><strong><?=setup_e($row['Category']?:'Uncategorized')?></strong></td><td><?=$row['ItemCount']?></td><td><?=$row['UnitCount']?></td><td><span class="status-badge<?=$row['LowStock']?' warn':''?>"><?=$row['LowStock']?></span></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<?php elseif($section==='payment-methods'): ?>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Payment Methods</h2><p>Active methods automatically populate cashier forms.</p></div><button type="button" class="btn btn-primary" data-add-payment>Add Method</button></div>
<div class="setup-toolbar"><div class="table-filter"><input type="search" data-table-filter="payments-table" placeholder="Search payment methods..."></div></div>
<?php if(!$paymentMethods):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('financial')?></span><h3>No payment methods configured</h3><p>Add the payment methods accepted at reception, laboratory and pharmacy.</p><button type="button" class="btn btn-primary" data-add-payment>Add Method</button></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="payments-table"><thead><tr><th>Method</th><th>Description</th><th>Status</th><th class="cell-actions">Actions</th></tr></thead><tbody>
<?php foreach($paymentMethods as $method):?>
<tr><td><strong><?=setup_e($method['MethodName'])?></strong></td><td><?=setup_e($method['Description']?:'—')?></td><td><span class="status-badge<?=$method['IsActive']?'':' danger'?>"><?=$method['IsActive']?'Active':'Inactive'?></span></td><td class="cell-actions"><button type="button" class="btn btn-secondary btn-sm" data-edit-payment='<?=setup_e(json_encode(['id'=>$method['PaymentMethodID'],'name'=>$method['MethodName'],'description'=>$method['Description'],'active'=>$method['IsActive']]))?>'>Edit</button></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<div class="modal-overlay" id="paymentModal"><div class="modal-box"><div class="modal-head"><div><h3 data-modal-title>Add Payment Method</h3><p>Accepted payment method for new transactions.</p></div><button type="button" class="modal-close" data-modal-close aria-label="Close">×</button></div>
<form method="post" id="paymentForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=setup_e($csrfToken)?>"><input type="hidden" name="setup_action" value="save_payment_method"><input type="hidden" name="method_id">
<div class="form-group"><label>Method Name *</label><input name="method_name" required minlength="2" maxlength="80"></div>
<div class="form-group"><label>Description</label><textarea name="description" rows="3" maxlength="500"></textarea></div>
<label class="check-control"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
</div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-modal-close>Cancel</button><button class="btn btn-primary">Save Method</button></div></form></div></div>

<?php elseif($section==='audit'): ?>
<section class="setup-table-panel">
<div class="workspace-panel-heading"><div><h2>Audit Log</h2><p>Administrative configuration and access changes recorded by the Setup module.</p></div></div>
<form method="get" class="setup-toolbar setup-audit-filters"><input type="hidden" name="section" value="audit">
<div class="table-filter"><input type="search" name="audit_search" placeholder="Search events..." value="<?=setup_e($auditSearch)?>"></div>
<select name="audit_module"><option value="">All modules</option><?php foreach($auditModules as $module):?><option value="<?=setup_e($module)?>" <?=$auditModule===$module?'selected':''?>><?=setup_e($module)?></option><?php endforeach;?></select>
<input type="date" name="audit_from" value="<?=setup_e($auditFrom)?>" aria-label="From date">
<input type="date" name="audit_to" value="<?=setup_e($auditTo)?>" aria-label="To date">
<button class="btn btn-secondary">Filter</button>
<a class="btn btn-secondary" href="setup.php?section=audit">Reset</a>
</form>
<?php if(!$auditRows):?>
<div class="setup-empty"><span class="setup-module-icon"><?=setup_icon('audit')?></span><h3>No matching audit events</h3><p>Administrative changes made in Setup will appear here.</p></div>
<?php else:?>
<div class="data-table-wrap"><table class="data-table" id="audit-table"><thead><tr><th>Date &amp; Time</th><th>Actor</th><th>Event</th><th>Module</th><th>Record</th><th>Summary</th></tr></thead><tbody>
<?php foreach($auditRows as $row):?>
<tr><td><?=setup_e(date('d M Y H:i',strtotime($row['CreatedAt'])))?></td><td><?=setup_e($row['userlegalname']??'System')?></td><td><?=setup_e($row['EventType'])?></td><td><?=setup_e($row['EntityType'])?></td><td><?=setup_e($row['EntityID']?:'—')?></td><td><?=setup_e($row['Summary'])?></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<?php endif; ?></main>
<script>
(()=>{const menu=document.querySelector('[data-menu="notifications"]'),button=menu?.querySelector('.icon-btn');button?.addEventListener('click',e=>{e.stopPropagation();menu.classList.toggle('open')});document.addEventListener('click',e=>{if(menu&&!menu.contains(e.target))menu.classList.remove('open')});})();
(()=>{const form=document.getElementById('permission-form');if(!form)return;
const boxes=[...form.querySelectorAll('input[name="permissions[]"]')];
const count=document.getElementById('permission-count');
const search=document.getElementById('permission-search');
const bar=document.getElementById('permission-save-bar');
const groups=[...document.querySelectorAll('[data-permission-group]')];
let dirty=false;
const ask=options=>window.TDCSetup?window.TDCSetup.confirm(options):Promise.resolve(window.confirm(options.message||options.title||'Are you sure?'));
const sync=()=>{const total=boxes.length,checked=boxes.filter(b=>b.checked).length;
if(count)count.textContent=checked+' of '+total+' enabled';
groups.forEach(group=>{const items=[...group.querySelectorAll('.permission-item')];
const visible=items.filter(i=>!i.hidden);
const chosen=items.filter(i=>i.querySelector('input').checked).length;
const counter=group.querySelector('[data-group-count]');
if(counter)counter.textContent=chosen+' / '+items.length+' selected';
const toggle=group.querySelector('[data-select-module]');
if(toggle){const v=visible.filter(i=>i.querySelector('input').checked).length;
toggle.checked=visible.length>0&&v===visible.length;toggle.indeterminate=v>0&&v<visible.length;}});};
const setDirty=v=>{dirty=v;if(bar)bar.hidden=!v;};
boxes.forEach(box=>box.addEventListener('change',()=>{setDirty(true);sync()}));
document.querySelectorAll('[data-select-module]').forEach(toggle=>toggle.addEventListener('change',()=>{toggle.closest('[data-permission-group]').querySelectorAll('.permission-item:not([hidden]) input').forEach(box=>box.checked=toggle.checked);setDirty(true);sync()}));
groups.forEach(group=>{const head=group.querySelector('[data-group-toggle]');
if(head)head.addEventListener('click',event=>{if(event.target.closest('input,button,a,label'))return;
group.classList.toggle('collapsed');
head.setAttribute('aria-expanded',String(!group.classList.contains('collapsed')));});});
document.querySelectorAll('[data-collapse-all]').forEach(button=>button.addEventListener('click',()=>groups.forEach(group=>{group.classList.add('collapsed');group.querySelector('[data-group-toggle]')?.setAttribute('aria-expanded','false')})));
document.querySelectorAll('[data-expand-all]').forEach(button=>button.addEventListener('click',()=>groups.forEach(group=>{group.classList.remove('collapsed');group.querySelector('[data-group-toggle]')?.setAttribute('aria-expanded','true')})));
document.getElementById('permission-clear')?.addEventListener('click',async()=>{const ok=await ask({title:'Clear all permissions?',message:'Every permission will be removed from this role until you save the change.',confirmLabel:'Clear All',destructive:true});if(!ok)return;boxes.forEach(box=>box.checked=false);setDirty(true);sync();});
document.getElementById('permission-reset')?.addEventListener('click',()=>{form.reset();setDirty(false);sync();});
if(search)search.addEventListener('input',()=>{const term=search.value.trim().toLowerCase();
document.querySelectorAll('.permission-item').forEach(item=>{const match=term===''||(item.dataset.search||'').includes(term);item.hidden=!match;
if(term&&match)item.closest('[data-permission-group]')?.classList.remove('collapsed');});
groups.forEach(group=>{group.hidden=![...group.querySelectorAll('.permission-item')].some(item=>!item.hidden)});
sync();});
form.addEventListener('submit',()=>{dirty=false;if(bar)bar.hidden=true});
window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue=''}});
sync();})();
</script>
<?php if($section==='users'):?>
<script>
(()=>{
const modal=document.getElementById('userSetupModal');
const openUser=data=>{if(!modal)return;
modal.querySelector('#userSetupTitle').textContent=data&&data.id?'Edit User':'Add User';
modal.querySelector('#su_user_id').value=data&&data.id?data.id:'';
modal.querySelector('#su_name').value=data&&data.userlegalname?data.userlegalname:'';
modal.querySelector('#su_username').value=data&&data.username?data.username:'';
modal.querySelector('#su_role').value=data&&data.role_id?data.role_id:'';
const doctor=modal.querySelector('#su_doctor');if(doctor)doctor.value=data&&data.DoctorID?data.DoctorID:'';
const pw=modal.querySelector('#su_password');pw.value='';pw.required=!(data&&data.id);
window.TDCSetup.openForm('userSetupModal');};
document.getElementById('addSetupUserBtn')?.addEventListener('click',()=>openUser(null));
document.querySelectorAll('[data-edit-user]').forEach(button=>button.addEventListener('click',()=>{let payload={};try{payload=JSON.parse(button.dataset.editUser||'{}')}catch(error){payload={}}openUser(payload)}));
document.querySelectorAll('[data-toggle-user]').forEach(button=>button.addEventListener('click',async()=>{const active=button.dataset.toggleUser==='1';
const ok=await window.TDCSetup.confirm({title:active?'Deactivate user?':'Activate user?',message:active?'This account will no longer be able to sign in. Historical records are unchanged.':'This account will be able to sign in again.',confirmLabel:active?'Deactivate':'Activate',destructive:active});
if(!ok)return;button.closest('form')?.submit();}));
document.getElementById('importUsersBtn')?.addEventListener('click',()=>window.TDCSetup.openForm('importUsersModal'));
})();
</script>
<?php endif;?>
<script>
window.TDCSetup=(()=>{
const focusable='a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
let lastFocus=null,confirmModal=null,confirmResolve=null;
const focusables=root=>[...root.querySelectorAll(focusable)].filter(el=>el.offsetWidth>0||el.offsetHeight>0||el===document.activeElement);
function closeModal(target){const modal=target||document.querySelector('.modal-overlay.show');if(!modal)return;
modal.classList.remove('show');modal.setAttribute('aria-hidden','true');
if(!document.querySelector('.modal-overlay.show'))document.body.classList.remove('modal-open');
if(lastFocus&&document.contains(lastFocus)){lastFocus.focus();lastFocus=null}}
function openModal(modal,opener){if(!modal)return;
document.querySelectorAll('.modal-overlay.show').forEach(other=>{if(other!==modal)closeModal(other)});
lastFocus=opener||document.activeElement;
modal.classList.add('show');modal.setAttribute('aria-hidden','false');
document.body.classList.add('modal-open');
const target=modal.querySelector('[data-autofocus]')||focusables(modal)[0];
if(target)target.focus();}
function openForm(id,opener){const modal=document.getElementById(id);if(!modal)return;openModal(modal,opener);
const body=modal.querySelector('.modal-body');if(body)body.scrollTop=0;}
function toast(message,type){let host=document.querySelector('.setup-toast-host');
if(!host){host=document.createElement('div');host.className='setup-toast-host';host.setAttribute('role','status');host.setAttribute('aria-live','polite');document.body.append(host);}
const item=document.createElement('div');item.className='setup-toast'+(type==='error'?' error':'');
const text=document.createElement('div');const strong=document.createElement('strong');strong.textContent=type==='error'?'Something went wrong':'Saved';
const span=document.createElement('span');span.textContent=message;text.append(strong,span);item.append(text);host.append(item);
window.setTimeout(()=>{item.style.opacity='0';item.style.transition='opacity 180ms ease';window.setTimeout(()=>item.remove(),200)},3600);}
function confirm(options){return new Promise(resolve=>{const config=options||{};
if(!confirmModal){confirmModal=document.createElement('div');confirmModal.className='modal-overlay';confirmModal.setAttribute('role','dialog');confirmModal.setAttribute('aria-modal','true');
confirmModal.innerHTML='<div class="modal-box" style="max-width:520px"><div class="modal-head"><div><h3 data-confirm-title></h3><p data-confirm-message></p></div><button type="button" class="modal-close" data-confirm-cancel aria-label="Close">&times;</button></div><div class="modal-body"><div class="modal-actions" style="position:static;margin:0;padding:0;border:0;background:transparent"><button type="button" class="btn btn-secondary" data-confirm-cancel>Cancel</button><button type="button" class="btn btn-primary" data-confirm-ok></button></div></div></div>';
document.body.append(confirmModal);
confirmModal.querySelectorAll('[data-confirm-cancel]').forEach(button=>button.addEventListener('click',()=>{const r=confirmResolve;confirmResolve=null;closeModal(confirmModal);r&&r(false)}));
confirmModal.addEventListener('click',event=>{if(event.target===confirmModal){const r=confirmResolve;confirmResolve=null;closeModal(confirmModal);r&&r(false)}});
confirmModal.querySelector('[data-confirm-ok]').addEventListener('click',()=>{const r=confirmResolve;confirmResolve=null;closeModal(confirmModal);r&&r(true)});}
confirmModal.querySelector('[data-confirm-title]').textContent=config.title||'Are you sure?';
confirmModal.querySelector('[data-confirm-message]').textContent=config.message||'';
const ok=confirmModal.querySelector('[data-confirm-ok]');
ok.textContent=config.confirmLabel||'Confirm';
ok.className='btn '+(config.destructive?'btn-danger':'btn-primary');
confirmResolve=resolve;openModal(confirmModal);ok.focus();});}
document.addEventListener('click',event=>{
const trigger=event.target.closest('[data-modal-open]');
if(trigger){event.preventDefault();openForm(trigger.dataset.modalOpen,trigger);return;}
if(event.target.closest('[data-modal-close]')){const modal=event.target.closest('.modal-overlay');if(modal){event.preventDefault();closeModal(modal)}return;}
const overlay=event.target.closest('.modal-overlay');
if(overlay&&event.target===overlay&&overlay.dataset.static!=='true')closeModal(overlay);});
document.addEventListener('keydown',event=>{
if(event.key==='Escape'){const modal=document.querySelector('.modal-overlay.show');if(modal){event.preventDefault();closeModal(modal)}return;}
if(event.key!=='Tab')return;
const modal=document.querySelector('.modal-overlay.show');if(!modal)return;
const items=focusables(modal);if(!items.length)return;
const first=items[0],last=items[items.length-1];
if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}});
document.addEventListener('click',event=>{
const toggle=event.target.closest('[data-action-menu]');
document.querySelectorAll('.action-menu.open').forEach(menu=>{if(!toggle||menu!==toggle.closest('.action-menu'))menu.classList.remove('open')});
if(toggle){event.preventDefault();toggle.closest('.action-menu').classList.toggle('open');}});
document.addEventListener('click',event=>{
const input=event.target.closest('[data-setup-filter]');if(!input)return;});
const searchIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';
document.querySelectorAll('[data-table-filter]').forEach(input=>{input.addEventListener('input',()=>{const term=input.value.trim().toLowerCase();
const scope=input.closest('.setup-page')||document;
scope.querySelectorAll('.data-table tbody tr').forEach(row=>{row.hidden=term!==''&&!row.textContent.toLowerCase().includes(term)});});});
document.querySelectorAll('.table-filter').forEach(host=>{if(!host.querySelector('svg'))host.insertAdjacentHTML('afterbegin',searchIcon)});
const notice=document.querySelector('.setup-notice');
if(notice&&notice.textContent.trim())toast(notice.textContent.trim(),'success');
const error=document.querySelector('.error-msg');
if(error&&error.textContent.trim())toast(error.textContent.trim(),'error');
return{openModal,closeModal,openForm,toast,confirm};})();
</script>
<script>
(()=>{
const T=window.TDCSetup;if(!T)return;
const fill=(form,data)=>{if(!form)return;form.reset();
Object.keys(data||{}).forEach(key=>{const field=form.elements[key];if(!field)return;
if(field.tagName===undefined)return;
if(field.type==='checkbox'){const v=data[key];field.checked=v===1||v===true||String(v)==='1';return;}
field.value=data[key]==null?'':String(data[key]);});};
const setTitle=(modal,text)=>{if(!text)return;const node=modal.querySelector('[data-modal-title]');if(node)node.textContent=text;};
document.querySelectorAll('[data-add-master]').forEach(button=>button.addEventListener('click',()=>{
const modal=document.getElementById(button.dataset.modal);if(!modal)return;
fill(modal.querySelector('form'),{});setTitle(modal,'Add '+button.dataset.label);T.openForm(button.dataset.modal,button);}));
document.querySelectorAll('[data-edit-master]').forEach(button=>button.addEventListener('click',()=>{
let payload={};try{payload=JSON.parse(button.dataset.editMaster||'{}')}catch(error){payload={}}
const modal=document.getElementById(payload.modal);if(!modal)return;
fill(modal.querySelector('form'),{record_id:payload.id,record_name:payload.name,description:payload.description,is_active:payload.active});
setTitle(modal,'Edit '+payload.label);T.openForm(payload.modal,button);}));
document.querySelectorAll('[data-edit-doctor]').forEach(button=>button.addEventListener('click',()=>{
let payload={};try{payload=JSON.parse(button.dataset.editDoctor||'{}')}catch(error){payload={}}
const modal=document.getElementById('doctorModal');if(!modal)return;
fill(modal.querySelector('form'),{doctor_id:payload.id,doctor_name_display:payload.name,fee:payload.fee,specialty:payload.specialty,doctor_user_id:payload.user});
T.openForm('doctorModal',button);}));
const openLab=payload=>{const modal=document.getElementById('labModal');if(!modal)return;
fill(modal.querySelector('form'),{service_id:payload.id,service_name:payload.name,category:payload.category,description:payload.description,price:payload.price,is_available:payload.available,is_active:payload.active});
setTitle(modal,payload.id?'Edit Laboratory Test':'Add Laboratory Test');T.openForm('labModal');};
document.querySelectorAll('[data-add-lab]').forEach(button=>button.addEventListener('click',()=>openLab({active:1,available:1})));
document.querySelectorAll('[data-edit-lab]').forEach(button=>button.addEventListener('click',()=>{
let payload={};try{payload=JSON.parse(button.dataset.editLab||'{}')}catch(error){payload={}}openLab(payload);}));
const openPayment=payload=>{const modal=document.getElementById('paymentModal');if(!modal)return;
fill(modal.querySelector('form'),{method_id:payload.id,method_name:payload.name,description:payload.description,is_active:payload.id?payload.active:1});
setTitle(modal,payload.id?'Edit Payment Method':'Add Payment Method');T.openForm('paymentModal');};
document.querySelectorAll('[data-add-payment]').forEach(button=>button.addEventListener('click',()=>openPayment({})));
document.querySelectorAll('[data-edit-payment]').forEach(button=>button.addEventListener('click',()=>{
let payload={};try{payload=JSON.parse(button.dataset.editPayment||'{}')}catch(error){payload={}}openPayment(payload);}));
document.querySelectorAll('[data-add-role]').forEach(button=>button.addEventListener('click',()=>{
const modal=document.getElementById('roleModal');if(!modal)return;
fill(modal.querySelector('form'),{});setTitle(modal,'Add Role');T.openForm('roleModal',button);}));
})();
</script>
</body></html>
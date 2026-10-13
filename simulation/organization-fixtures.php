<?php
// De-identifies the committed owner-authorized organization plan before the official IAM provisioner sees it.
// No live identities, sessions or credentials are queried. Reporting lines are retained between synthetic IDs.
use Arasya\Operations\Management\OrganizationOnboarding;
use Arasya\Operations\Management\OrganizationProvisioner;
use Arasya\Operations\Management\OrganizationReconciler;
$reference=dirname(__DIR__) . '/operations-api/database/reference';
$plan=json_decode((string)file_get_contents($reference . '/organization-onboarding.json'),true,32,JSON_THROW_ON_ERROR);
$roster=json_decode((string)file_get_contents($reference . '/organization-roster.json'),true,32,JSON_THROW_ON_ERROR);
$factory=json_decode((string)file_get_contents($reference . '/factory-onboarding.json'),true,32,JSON_THROW_ON_ERROR);
$names=[];$factoryStages=[];
foreach($factory['groups'] as $group) foreach($group['names'] as $name) $factoryStages[$name]=$group['stageIds'];
foreach($roster['people'] as $i=>$person) $names[$person['name']]='Sim Org ' . sprintf('%02d',$i+1);
$stagesBySynthetic=[];
foreach($roster['people'] as &$person) {
    $stagesBySynthetic[$names[$person['name']]]=$factoryStages[$person['name']]??[];
    $person['name']=$names[$person['name']];$person['title']='TEST organization responsibility';
} unset($person);
foreach($plan['people'] as &$person) {
    // The separate owner-approved factory matrix activates only its 29 production profiles in THIS isolated rehearsal.
    if (($factoryStages[$person['name']]??[])!==[]) {
        $person['status']='active';$person['applications']=array_values(array_unique([...($person['applications']??[]),'staff']));
        $person['roles']=array_values(array_unique([...($person['roles']??[]),'employee']));
    }
    $person['name']=$names[$person['name']];$person['username']=OrganizationOnboarding::username($person['name']);
    if(isset($person['manager'])) $person['manager']=$names[$person['manager']];
} unset($person);
foreach($plan['departments'] as &$department) if(isset($department['description'])) $department['description']='TEST departmental oversight; no authority inferred.'; unset($department);
$plan['pending']=['Reference organization contains unapproved reporting/role decisions; simulation retains the same lack of authority.'];
$onboarding=OrganizationOnboarding::fromArrays($plan,$roster);
$sink=new class implements \Arasya\Operations\Iam\CredentialSink {
    public array $rows=[];
    public function write(array $credential): void {$this->rows[]=$credential;}
    public function location(): string {return 'simulation private manifest';}
    public function count(): int {return count($this->rows);}
};
$provisioner=new OrganizationProvisioner($pdo,$container->managementService(),$container->organizationService(),new OrganizationReconciler($pdo,$container->managementService(),$container->employeeRepository()),$container->employeeRepository());
$organizationResult=$provisioner->apply($onboarding,$sink,'sim-organization-provision');
foreach($sink->rows as $row) {
    $s=$login($row['username'],$row['temporaryPassword']);$password='Sim Org ' . bin2hex(random_bytes(16)) . '!';
    $call('POST','/auth/password',['currentPassword'=>$row['temporaryPassword'],'newPassword'=>$password],$s);
    $stagesForPerson=$stagesBySynthetic[$row['name']];
    if($stagesForPerson!==[]) $call('PUT','/management/employees/' . $s['employeeUuid'] . '/stages',['stageIds'=>$stagesForPerson,'stageScopes'=>array_fill_keys($stagesForPerson,['trendhome','outletperdele','trendyol'])],$root);
    $key=str_replace('.','-',substr($row['username'],4));
    $accounts[$key]=['username'=>$row['username'],'password'=>$password,'employeeUuid'=>$s['employeeUuid'],'stages'=>$stagesForPerson,'locale'=>'ro','organizationProfile'=>true];
    if(($onboarding->people()[$row['name']]['principal']??null)==='ceo') $accounts['ceo']=$accounts[$key];
}
unset($sink);
$organizationProfiles=[];
foreach($onboarding->people() as $person) $organizationProfiles[]=['username'=>$person['username'],'department'=>$person['department'],'additionalDepartments'=>$person['additionalDepartments'],'status'=>$person['status'],'applications'=>$person['applications'],'roles'=>$person['roles'],'manager'=>$person['manager']===null?null:OrganizationOnboarding::username($person['manager']),'stages'=>$stagesBySynthetic[$person['name']],'documentScopes'=>$person['documentScopes'],'principal'=>$person['principal']];

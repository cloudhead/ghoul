

<?php
/*
   Ploy
  
   Simple deployment mechanism

   R.Lerdorf WePay Inc. Nov.2010
*/


class Ploy {
  public $ini_file = './ploy.ini'; 
  public $tmpdir   = '/tmp'; 
  public $defaults = array('scm'=>'svn', 'tz'=>'UTC', 'ssh_port'=>22, 'keep'=>10);
  public $prompt   = false;
  public $quiet     = false;
  public $loud      = false;
  public $rev       = '';

  function __construct($argv=NULL) {
    date_default_timezone_set('America/Los_Angeles');
    $this->rev = date("YmdHis");
    $this->log = new PloyLog($this->rev);
    if(php_sapi_name()=='cli') {
      $this->options($argv);
      $this->config();
      $this->deploy($argv[1]);
    }
  }

  // Modify this method to change the deploy strategy
  function deploy($target) {
    $targ = $this->targets[$target];
    $dir = $this->rev;
    $pwd = NULL;

    $cwd = getcwd();
    chdir($this->tmpdir);

    `{$targ['scm']} info --username {$targ['scm.user']} --password {$targ['scm.passwd']} --no-auth-cache --non-interactive --trust-server-cert {$targ['repository']} > $dir.info`;

    // Bundle application@revision into a directory named with the rev, in /tmp,
    // Don't include files which aren't version controlled.
    if(!is_dir($this->rev)) {
      $this->log->output("Exporting {$targ['repository']} to $dir");
      `{$targ['scm']} export -q --username {$targ['scm.user']} --password {$targ['scm.passwd']} --no-auth-cache --non-interactive --trust-server-cert {$targ['repository']} $dir`;
      $this->log->rollback_set("rm -rf $dir.info $dir");
    }

    // Clean up targets we don't need before pushing the code
    if(is_dir("$dir/deploy/targets")) {
      foreach(glob("$dir/deploy/targets/*") as $t) {
        if(basename($t)!=$target) `rm -rf $t`;
      }
    }
    `tar czf $dir.tar.gz $dir`;

    // Now push the tarball to each host
    foreach($targ['hosts'] as $ip) {
      $host = new Host($ip, $targ, $this->log, $pwd);
      $targ['ssh'][$ip] = $host;
      $host->exec("mkdir -p {$targ['deploy_to']}/releases");

      // Alternatively you can use $host->sftp() here
      $host->scp("$dir.tar.gz", "{$targ['deploy_to']}/releases/$dir.tar.gz");
      $this->log->rollback_add("rm -f {$targ['deploy_to']}/releases/$dir.tar.gz", $ip);

      // Make sure the file got there uncorrupted
      $result = $host->exec("md5sum -b {$targ['deploy_to']}/releases/$dir.tar.gz");
      list($remote_md5,$junk) = explode(' ',$result,2);
      $this->log->verbose("File uploaded and checksum matched");
    }

    // Multiple loops to do these almost in parallel 
    foreach($targ['ssh'] as $ip=>$host) {
      $host->exec("cd {$targ['deploy_to']}/releases && tar zxf $dir.tar.gz && rm $dir.tar.gz && cd $dir && REVISION=$dir make $target");
      if(strlen(trim($dir))) $this->log->rollback_add("rm -rf {$targ['deploy_to']}/releases/$dir", $ip);
    }

    // Sanity check
    foreach($targ['ssh'] as $ip=>$host) {
      $current_version[$ip] = $host->exec("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php'");
      if($current_version[$ip]) $this->log->rollback_add("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php?user={$this->user}&rel=rollback&rev={$current_version[$ip]}'", $ip);
    }

    // Move the symlink into place and hit the local setrev script
    foreach($targ['ssh'] as $ip=>$host) {
      $this->log->output("Moving symlink from {$current_version[$ip]} to $dir on {$host->name}");
      $host->exec("ln -s {$targ['deploy_to']}/releases/$dir {$targ['deploy_to']}/new_current && mv -Tf {$targ['deploy_to']}/new_current {$targ['deploy_to']}/current");
      if($current_version[$ip]) $this->log->rollback_add("ln -s {$targ['deploy_to']}/releases/{$current_version[$ip]} {$targ['deploy_to']}/new_current && mv -Tf {$targ['deploy_to']}/new_current {$targ['deploy_to']}/current");
      $this->log->output("Symlink moved, version $dir is now active");
      $host->exec("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php?user={$this->user}&rel={$targ['repository']}&rev=$dir'");
      $this->log->rollback_add("curl -s -S -H 'Host: {$targ['application']}' 'localhost/setrev.php?user={$this->user}&rel={$targ['repository']}&rev={$current_version[$ip]}'", $ip);
    }

    // Deploy was good - non-critical cleanup after this point
    $this->log->rollback_set('');

    // Delete previous targets, but keep $targ[keep] of them around
    foreach($targ['ssh'] as $ip=>$host) {
      $keep = $targ['keep'] + 1;  // The number of old revisions to keep around on the server
      $host->exec("cd {$targ['deploy_to']}/releases && j=0; for i in `ls -d1at ./20????????????`; do j=`expr \$j + 1`; if [ \"\$j\" -ge $keep ]; then rm -rf \$i; fi; done");
    }

    // And get rid of the local installation files
    `rm -rf $dir.info $dir.tar.gz $dir`;
    chdir($cwd);
    $this->log->output("SUCCESS!");
  }
}



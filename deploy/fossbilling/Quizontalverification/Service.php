<?php
declare(strict_types=1); namespace Box\Mod\Quizontalverification;
use FOSSBilling\InformationException;
class Service implements \FOSSBilling\InjectionAwareInterface { protected ?\Pimple\Container $di=null; public function setDi(\Pimple\Container $d):void{$this->di=$d;} public function getDi():?\Pimple\Container{return $this->di;}
 public function install():bool{$this->di['db']->exec('CREATE TABLE IF NOT EXISTS quizontal_email_resend (client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, sent_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');return true;}
 /** Send our branded verification message after every signup without enabling FOSSBilling's global access lock. */
 public static function onAfterClientSignUp(\Box_Event $event): bool { try { $di=$event->getDi(); $client=$di['db']->load('Client',(int)($event->getParameters()['id']??0)); if($client instanceof \Model_Client && empty($client->email_approved)) $di['mod_service']('client')->sendEmailConfirmationForClient($client); } catch (\Throwable $e) { error_log('Quizontal verification signup email failed: '.$e->getMessage()); } return true; }
 public function resend(\Model_Client $client):int { if($client->email_approved) return 0; $s=$this->di['pdo']->prepare('SELECT sent_at FROM quizontal_email_resend WHERE client_id=?');$s->execute([$client->id]);$last=$s->fetchColumn();$left=$last?60-(time()-strtotime((string)$last)):0;if($left>0) throw new InformationException('Please wait '.$left.' seconds before requesting another email.');$this->di['mod_service']('client')->sendEmailConfirmationForClient($client);$s=$this->di['pdo']->prepare('INSERT INTO quizontal_email_resend (client_id,sent_at) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE sent_at=NOW()');$s->execute([$client->id]);return 60; }
}

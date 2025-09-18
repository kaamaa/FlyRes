<?php

namespace App;

//Aus-/Anschalten der Protokollfunktion
define('EH_LOG',true);
//Pfad zur Protokolldatei
define('EH_LOGFILE_PATH', "eventlog.txt");
//Pfad zu clickstatistic
define('EH_CLICKSTATISTIC_PATH',"clicklog.txt");

class Logging
{
	private static function write($handle, $msg)
	{
		if ($handle)
		{
			$cyles = 0; // Anzahl der bislang durchlaufenen Wartezyklen
			// Datei für den exclusiven Zugriff sperren
			while (!flock($handle, LOCK_EX))
			{
				// Datei konnte nicht für den exklusiven Zugriff gesperrt werden. Daher 0.2 Sekunde warten
				sleep(0.2);
				$cyles++;
				// Wenn 10 Zyklen vorbei sind aufgeben
				if ($cyles > 10)
				{
					// Datei schliessen
					fclose($handle);
					return;
				}
			}
			//Fehlermedlung eintragen
			fwrite($handle, date("d.m.Y H:i:s | ", time()) . $msg . "\r\n");
			flock($handle, LOCK_UN);
			fclose($handle);
		}

	}

	public static function writeMsg($msg)
	{
		$handle = fopen(EH_LOGFILE_PATH, "a+");
		self::write($handle, $msg);
	}

	public static function writeClickCountMsg($msg)
	{
		$handle = fopen(EH_CLICKSTATISTIC_PATH, "a+");
		self::write($handle, $msg);
	}

          public static function getLog()
	{
		$handle = fopen(EH_LOGFILE_PATH, "r");
		$content = fread ($handle, filesize (EH_LOGFILE_PATH));
		fclose ($handle);
		echo $content;
	}
        
        public static function writeSessionMsg($msg, \Symfony\Component\HttpFoundation\Session\Session $session)
	{
                self::writeMsg('===================================================');
                self::writeMsg($msg);
                self::writeMsg('');
                self::writeMsg($session->getId());
                self::writeMsg('');
                self::writeMsg($session->get('_security_secured_area'));
                self::writeMsg('===================================================');
	}
}
?>

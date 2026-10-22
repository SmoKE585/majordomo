<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of playSound
 * @param mixed $filename File name
 * @param mixed $exclusive Exclusive (default 0)
 * @param mixed $priority Priority (default 0)
 * @return void
 */
function playSound($filename, $exclusive = 0, $priority = 0)
{
    global $ignoreSound;

    if (file_exists(ROOT . 'cms/sounds/' . $filename . '.mp3'))
        $filename = ROOT . 'cms/sounds/' . $filename . '.mp3';
    elseif (file_exists(ROOT . 'cms/sounds/' . $filename))
        $filename = ROOT . 'cms/sounds/' . $filename;

    if (defined('SETTINGS_HOOK_BEFORE_PLAYSOUND') && SETTINGS_HOOK_BEFORE_PLAYSOUND != '')
        eval(SETTINGS_HOOK_BEFORE_PLAYSOUND);

    if (!$ignoreSound) {
        if (file_exists($filename)) {
            // rc/ (with the bundled Windows player) was removed; use the
            // configured AUDIO_PLAYER and skip playback when it is not set.
            $audio_player = (defined('AUDIO_PLAYER') && AUDIO_PLAYER != '') ? AUDIO_PLAYER : '';
            if ($audio_player == '' && !IsWindowsOS()) {
                $audio_player = 'mplayer';
            }
            if ($audio_player != '') {
                if (IsWindowsOS()) {
                    safe_exec($audio_player . ' "' . $filename . '"', $exclusive, $priority);
                } else {
                    safe_exec($audio_player . ' ' . $filename . " >/dev/null 2>&1", $exclusive, $priority);
                }
            }
        }
    }

    if (defined('SETTINGS_HOOK_AFTER_PLAYSOUND') && SETTINGS_HOOK_AFTER_PLAYSOUND != '')
        eval(SETTINGS_HOOK_AFTER_PLAYSOUND);
}

/**
 * Summary of processCommand
 * @param mixed $command Command
 * @return mixed
 */
function processCommand($command)
{
    global $pattern_matched;
    if (!$pattern_matched) {
        return getObject("ThisComputer")->callMethod("commandReceived", array("command" => $command));
    }
}

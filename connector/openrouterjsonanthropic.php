<?php

$enginePath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

// --- START: Standalone Cache Helper Functions ---

if (!function_exists('connector_cacheReadFromFile')) {
    function connector_cacheReadFromFile($fileName, $isUserAssistantCache = false)
    {
        $cacheFilePath = __DIR__ . "/../temp/" . $fileName;
        if (!file_exists($cacheFilePath) || filesize($cacheFilePath) === 0)
            return 0;
        $file = @fopen($cacheFilePath, "r");
        if (!$file) {
            error_log("Failed to open cache file for reading: " . $fileName);
            return 0;
        }
        $timestampLine = trim(@fgets($file));
        if ($timestampLine === false || !is_numeric($timestampLine) || $timestampLine <= 0) {
            fclose($file);
            @unlink($cacheFilePath);
            error_log("Invalid or missing timestamp in cache file: " . $fileName);
            return 0;
        }
        $timestamp = (int) $timestampLine;
        $lastIndex = -1;
        $lastHash = null;
        $content = "";
        if ($isUserAssistantCache) {
            $lastIndexLine = trim(@fgets($file));
            if ($lastIndexLine === false || !is_numeric($lastIndexLine) || $lastIndexLine < -1) {
                fclose($file);
                @unlink($cacheFilePath);
                error_log("Invalid or missing lastIndex in cache file: " . $fileName);
                return 0;
            }
            $lastIndex = (int) $lastIndexLine;
            $lastHashLine = trim(@fgets($file));
            if ($lastHashLine === false || (empty($lastHashLine) && $lastIndex > -1)) {
                fclose($file);
                @unlink($cacheFilePath);
                error_log("Invalid or missing lastHash in cache file: " . $fileName);
                return 0;
            }
            $lastHash = $lastHashLine;
            while (!feof($file)) {
                $line = @fgets($file);
                if ($line === false)
                    break;
                $content .= $line;
            }
        } else { // System cache
            while (!feof($file)) {
                $line = @fgets($file);
                if ($line === false)
                    break;
                $content .= $line;
            }
        }
        fclose($file);
        return array('timestamp' => $timestamp, 'lastIndex' => $lastIndex, 'lastHash' => $lastHash, 'content' => $content);
    }
}

if (!function_exists("connector_cacheWriteToFile")) {
    function connector_cacheWriteToFile($fileName, $content, $timestamp, $lastIndexIncluded = null, $lastHashIncluded = null)
    {
        $filePath = __DIR__ . "/../temp/" . $fileName;
        $folderPath = dirname($filePath);
        if (!is_dir($folderPath)) {
            if (!@mkdir($folderPath, 0755, true)) {
                error_log("Failed to create cache directory: " . $folderPath);
                return false;
            }
        }
        $fileContent = $timestamp . "\n";
        if ($lastIndexIncluded !== null && is_numeric($lastIndexIncluded)) {
            $fileContent .= $lastIndexIncluded . "\n";
            $fileContent .= (isset($lastHashIncluded) ? $lastHashIncluded : '') . "\n";
        }
        $fileContent .= $content;
        $bytesWritten = @file_put_contents($filePath, $fileContent, LOCK_EX);
        if ($bytesWritten === false) {
            error_log("Failed to write cache file: " . $filePath);
        }
        return ($bytesWritten !== false);
    }
}

// New function to build combined dialogue content
if (!function_exists('connector_buildCombinedDialogue')) {
    function connector_buildCombinedDialogue($contextData, $numMessagesToKeepUncached, $connectorName, $herikaName)
    {
        $n_ctxsize = count($contextData);
        $cacheThresholdIndex = max(0, $n_ctxsize - $numMessagesToKeepUncached);
        $lastAggregatedIndex = $cacheThresholdIndex - 1;
        $combinedContentToCache = "";
        $lastAggregatedElement = null;
        $lastAggregatedContentForHash = "";

        for ($n = 0; $n <= $lastAggregatedIndex; $n++) {
            if (!isset($contextData[$n]))
                continue;
            $element = $contextData[$n];
            if (isset($element["role"]) && $element["role"] != "system") {
                $contentString = '';
                if (is_string($element['content'])) {
                    $contentString = $element['content'];
                } elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) {
                    $contentString = $element['content'][0]['text'];
                }
                $trimmedContent = trim($contentString);
                if (!empty($trimmedContent)) {
                    $sequencePrefix = "Seq[" . ($n + 1) . "]: ";
                    $lineContent = $sequencePrefix . $element['role'] . ": " . $trimmedContent . "\n";
                    $combinedContentToCache .= $lineContent;
                    $lastAggregatedElement = $element;
                    $lastAggregatedContentForHash = $trimmedContent;
                }
            }
        }

        $finalLastAggregatedHash = ($lastAggregatedElement !== null) ? md5($lastAggregatedContentForHash) : '';

        return array(
            'content' => $combinedContentToCache,
            'lastAggregatedIndex' => $lastAggregatedIndex,
            'lastAggregatedHash' => $finalLastAggregatedHash,
            'startIndexForIndividual' => $cacheThresholdIndex
        );
    }
}
// --- END: Standalone Cache Helper Functions ---

function logMessage(string|array $message, string $level = 'INFO', string $logFile = './application.log'): bool
{
    // Get the current timestamp in a readable format
    $timestamp = date('Y-m-d H:i:s');

    // If the message is an array, convert it to a JSON string
    if (is_array($message)) {
        // Use JSON_PRETTY_PRINT for better readability in the log file,
        // or remove it for a single-line compact JSON string.
        $formattedMessage = json_encode($message, JSON_PRETTY_PRINT);
        if ($formattedMessage === false) {
            // Fallback if json_encode fails (e.g., circular reference)
            $formattedMessage = "Failed to encode array to JSON. Original: " . print_r($message, true);
        }
    } else {
        // If it's not an array, use the message as is
        $formattedMessage = (string) $message; // Ensure it's treated as a string
    }

    // Format the log entry: [YYYY-MM-DD HH:MM:SS] LEVEL: Your message
    $logEntry = "[{$timestamp}] {$level}: {$formattedMessage}\n";

    // Attempt to write the log entry to the file
    // FILE_APPEND ensures the content is added to the end of the file.
    // LOCK_EX acquires an exclusive lock, preventing race conditions during concurrent writes.
    $result = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

    // Check if the write operation was successful
    if ($result === false) {
        // If writing failed, log an error to PHP's default error log
        error_log("Failed to write to log file: {$logFile}. Original message: " . (is_array($message) ? json_encode($message) : $message));
        return false;
    }

    return true;
}

function extract_and_remove_section(&$text, $section_name)
{
    // Pattern includes section header and content, up to but not including next top-level header or end
    $pattern = '/(^# ' . preg_quote($section_name, '/') . '.*?)(?=^# |\z)/msi';

    if (preg_match($pattern, $text, $matches)) {
        // Remove section from $text by replacing with empty string
        $text = preg_replace($pattern, '', $text, 1);
        return trim($matches[1]);
    } else {
        return '';
    }
}

class openrouterjsonanthropic
{
    // --- Properties ---
    public $primary_handler;
    public $finfo;
    public $name;
    private $_functionName;
    private $_parameterBuff;
    private $_commandBuffer;
    public $_extractedbuffer;
    private $_buffer;
    private $_dataSent;
    private $_rawbuffer;
    private $_forcedClose = false;
    public $_jsonResponsesEncoded = array();



    public function __construct()
    {
        $this->name = "openrouterjsonanthropic";
        $this->_commandBuffer = array();
        $this->_buffer = "";
        $this->_extractedbuffer = "";
        $this->_dataSent = null;
        $this->_rawbuffer = "";
        $this->_forcedClose = false;
        $this->_functionName = null;
        $this->_parameterBuff = "";
        $this->_jsonResponsesEncoded = array();
    }

    public function open($contextData, $customParms)
    {
        // --- Setup and Logging ---

        $herikaNameForLog = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        $herikaName = $herikaNameForLog;
        $incoming_ctx_size = count($contextData);
        error_log("[{$this->name}:{$herikaName}] OPEN START: Received contextData with {$incoming_ctx_size} elements.");

        // --- Config ---
        $url = isset($GLOBALS["CONNECTOR"][$this->name]["url"]) ? $GLOBALS["CONNECTOR"][$this->name]["url"] : '';
        $MAX_TOKENS = ((isset($GLOBALS["CONNECTOR"][$this->name]["max_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["max_tokens"] : 4096) + 0);
        $model = (isset($GLOBALS["CONNECTOR"][$this->name]["model"])) ? $GLOBALS["CONNECTOR"][$this->name]["model"] : 'anthropic/claude-3-haiku-20240307';

        // --- Configurable Cache Settings Reading ---
        $sysCacheStrategy = 'ttl';
        if (isset($GLOBALS["CONNECTOR"][$this->name]["system_cache_strategy"]) && in_array($GLOBALS["CONNECTOR"][$this->name]["system_cache_strategy"], array('content', 'ttl'))) {
            $sysCacheStrategy = $GLOBALS["CONNECTOR"][$this->name]["system_cache_strategy"];
        }
        $sysCacheTTL = 7200;
        if (isset($GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"]) && is_numeric($GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"]) && $GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"] >= 0) {
            $sysCacheTTL = (int) $GLOBALS["CONNECTOR"][$this->name]["system_cache_ttl"];
        }
        $dialogueCacheTTL = 7200;
        if (isset($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"]) && is_numeric($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"]) && $GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"] >= 0) {
            $dialogueCacheTTL = (int) $GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_ttl"];
        }
        $numMessagesToKeepUncached = 15;
        if (isset($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"]) && is_numeric($GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"]) && $GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"] >= 0) {
            $numMessagesToKeepUncached = (int) $GLOBALS["CONNECTOR"][$this->name]["dialogue_cache_uncached_count"];
        }
        $logPrefix = "[{$this->name}:{$herikaName}]";
        error_log("{$logPrefix} Cache Config: SysStrategy={$sysCacheStrategy}, SysTTL={$sysCacheTTL}, DialogueTTL={$dialogueCacheTTL}, UncachedCount={$numMessagesToKeepUncached}");
        // --- End Cache Settings Reading ---

        // --- Context Preprocessing ---
        $contextDataCopyPreCache = array();
        $skipped_count = 0;
        foreach ($contextData as $n => $element) {
            if (is_array($element) && isset($element['role']) && isset($element['content'])) {
                $contentCheck = null;
                if (is_string($element['content'])) {
                    $contentCheck = trim($element["content"]);
                    if (empty($contentCheck)) {
                        $contentCheck = null;
                    } else {
                        $element['content'] = $contentCheck;
                    }
                } elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) {
                    $contentCheck = trim($element['content'][0]['text']);
                    if (empty($contentCheck)) {
                        $contentCheck = null;
                    } else {
                        $element['content'][0]['text'] = $contentCheck;
                    }
                }
                if ($contentCheck !== null) {
                    $contextDataCopyPreCache[] = $element;
                } else {
                    $skipped_count++;
                }
            } else {
                error_log("{$logPrefix} OPEN PREPROCESS: Skipped malformed element at index {$n}.");
                $skipped_count++;
            }
        }
        $contextDataOrig = $contextDataCopyPreCache;
        $n_ctxsize = count($contextDataOrig);
        error_log("{$logPrefix} OPEN PREPROCESS: Processed contextDataOrig has {$n_ctxsize} elements. Skipped {$skipped_count}.");
        // --- End Preprocessing ---

        // --- Caching Setup with 2-cache approach ---
        $cacheSystemFile = "system_cache_json_{$herikaName}.tmp";
        $cacheCombinedDialogueFile = "combined_dialogue_cache_json_{$herikaName}.tmp"; // Single file for dialogue
        $cacheSystemMessages = connector_cacheReadFromFile($cacheSystemFile, false);
        $cacheCombinedDialogueMessages = connector_cacheReadFromFile($cacheCombinedDialogueFile, true); // Use the same format with lastIndex/hash

        $currentTime = time();
        $isCombinedDialogueCacheValid = false;

        // Check if combined dialogue cache is valid
        if (is_array($cacheCombinedDialogueMessages) && isset($cacheCombinedDialogueMessages["timestamp"])) {
            if (($currentTime - $cacheCombinedDialogueMessages["timestamp"]) <= $dialogueCacheTTL) {
                $isCombinedDialogueCacheValid = true;
            }
        }

        // --- Build Final Message List ---
        $finalMessagesToSend = array();
        $processedFirstSystem = false;
        $systemContentForCacheFile = null;


        // NEW: Append AVAILABLE ACTION text and additional messages to ensure actions are sent to LLM
        // Use dynamic names from globals or context
        $playerName = isset($GLOBALS["PLAYER_NAME"]) ? $GLOBALS["PLAYER_NAME"] : "Alethia";
        $characterName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : "Brelyna Maryon";
        $characters = DataBeingsInRange();

        $cacheControlType = array("type" => "ephemeral", "ttl" => "1h");

        $actionsText = "\n" .
            "AVAILABLE ACTION: Inspect (Inspects target character's OUTFIT and GEAR. JUST REPLY something like 'Let me see' and wait)\n" .
            "AVAILABLE ACTION: InspectSurroundings (Looks for beings or enemies nearby)\n" .
            "AVAILABLE ACTION: ExchangeItems (Initiates trading or exchange items with {$playerName}.)\n" .
            "AVAILABLE ACTION: Attack (Attacks actor, npc or being.)\n" .
            "AVAILABLE ACTION: Hunt (Try to hunt/kill ar animal)\n" .
            "AVAILABLE ACTION: ListInventory (Search in {$characterName}'s inventory, backpack or pocket. List inventory)\n" .
            "AVAILABLE ACTION: SheatheWeapon (Sheates current weapon)\n" .
            "AVAILABLE ACTION: LetsRelax (Stop questing. Relax and rest.)\n" .
            "AVAILABLE ACTION: ReadQuestJournal (Only use if {$playerName} explicitly ask for a quest. Get info about current quests)\n" .
            "AVAILABLE ACTION: IncreaseWalkSpeed (Increase {$characterName} speed when moving or travelling)\n" .
            "AVAILABLE ACTION: DecreaseWalkSpeed (Decrease {$characterName} speed when moving or travelling)\n" .
            "AVAILABLE ACTION: WaitHere ({$characterName} waits and stands at the current place)\n" .
            "AVAILABLE ACTION: StartLooting (Start looting the area)\n" .
            "AVAILABLE ACTION: StopLooting (Stop looting the area)\n" .
            "AVAILABLE ACTION: IncreaseArousal (Signal that you're becoming more aroused - use when events are stimulating, but prioritize other actions that directly advance the scene)\n" .
            "AVAILABLE ACTION: DecreaseArousal (Signal that you're becoming less aroused - use when excitement is dampening, but prioritize other actions that directly advance the scene)\n" .
            "AVAILABLE ACTION: Masturbate (Begin pleasuring yourself without a partner - use when alone or to arouse others)\n" .
            "AVAILABLE ACTION: StartVaginal (Initiate vaginal intercourse with the target - one of the primary sex actions)\n" .
            "AVAILABLE ACTION: StartAnal (Initiate anal intercourse with him - an intimate and intense sexual action)\n" .
            "AVAILABLE ACTION: StartThreesome (Initiate sexual activity with multiple partners simultaneously - use for three-person encounters)\n" .
            "AVAILABLE ACTION: StartOrgy (Begin group sexual activity with multiple willing participants in the vicinity)\n" .
            "AVAILABLE ACTION: PutOnClothes (Dress yourself in available clothing and armor - restores modesty)\n" .
            "AVAILABLE ACTION: RemoveClothes (Take off all clothing and armor - necessary for intimate activities)\n" .
            "AVAILABLE ACTION: TakeASeat (Sit down) \n" .
            "AVAILABLE ACTION: StartBlowjob (Perform or receive oral sex on the penis)\n" .
            "AVAILABLE ACTION: StartHandjob (Stimulate his penis with your hands or have him stimulate yours)\n" .
            "AVAILABLE ACTION: Hug (Embrace him in a warm hug - shows affection, comfort, or friendship)\n" .
            "AVAILABLE ACTION: Kiss (Kiss him on the lips - expresses romantic or sexual interest)\n" .
            "AVAILABLE ACTION: Molest (Force unwanted sexual contact on him - a criminal act of assault (use with caution))\n" .
            "AVAILABLE ACTION: Grope (Touch and fondle his body in a sexual manner - shows desire and dominance)\n" .
            "AVAILABLE ACTION: PinchNipples (Firmly pinch and manipulate his nipples - stimulates sensitive nerve endings)\n" .
            "AVAILABLE ACTION: Talk\n" .
            "AVAILABLE ACTION: SpankAss (Strike her buttocks firmly - can be playful, disciplinary, or erotic)\n" .
            "AVAILABLE ACTION: SpankTits (Strike her breasts firmly - an intense erotic act that mixes pain and pleasure)\n" .
            "AVAILABLE ACTION: Fight ((actor) engages non lethtal combat with another actor, using weapons)\n" .
            "AVAILABLE ACTION: ExitLocation ((actor) travels to home/origin place.Returns home.)\n" .
            "AVAILABLE ACTION: TravelTo (Long distance travel command. Use it to move to major locations and landmarks, or nearby buildings.) \n" .
            "AVAILABLE ACTION: UseSoulGaze (Take a look around and see what {$playerName} sees. Use this action to get information about what is visible and how it looks.)\n";
        $dynamicEnvironment = "";

        foreach ($contextDataOrig as $n => $element) {
            if (isset($element["role"]) && $element["role"] == "system") {
                $systemContentString = '';
                if (is_string($element['content'])) {
                    $systemContentString = $element['content'];
                } elseif (is_array($element['content']) && isset($element['content'][0]['type']) && $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])) {
                    $systemContentString = $element['content'][0]['text'];
                }
                $trimmedSystemContent = trim($systemContentString);
                if (!$processedFirstSystem && !empty($trimmedSystemContent)) {
                    $processedFirstSystem = true;
                    $systemContentOriginal = $trimmedSystemContent;
                    $systemContentCurrent = $systemContentOriginal;

                    // Conditional System Cache Check
                    $isSystemCacheValid = false;
                    if (is_array($cacheSystemMessages) && isset($cacheSystemMessages['timestamp'])) {
                        if ($sysCacheStrategy === 'ttl') {
                            if (($currentTime - $cacheSystemMessages['timestamp']) <= $sysCacheTTL) {
                                $isSystemCacheValid = true;
                                error_log("{$logPrefix} System cache valid based on TTL (Strategy B).");
                            } else {
                                error_log("{$logPrefix} System cache expired based on TTL (Strategy B).");
                            }
                        } else {
                            if (isset($cacheSystemMessages['content']) && $cacheSystemMessages['content'] === $systemContentCurrent) {
                                $isSystemCacheValid = true;
                                error_log("{$logPrefix} System cache valid based on content match (Strategy A).");
                            } else {
                                error_log("{$logPrefix} System cache invalid based on content mismatch (Strategy A).");
                            }
                        }
                    } else {
                        error_log("{$logPrefix} System cache miss (file invalid or missing).");
                    }

                    if ($isSystemCacheValid) {

                        $environmental = extract_and_remove_section($systemContentCurrent, 'Environmental Context');
                        $additional = extract_and_remove_section($systemContentCurrent, 'Additional Information');
                        $dynamicEnvironment = $environmental . "\n\n" . $additional;
                        $finalSend = $cacheSystemMessages["content"] . "\n" . $actionsText;

                        $finalMessagesToSend[] = array("role" => "system", "content" => array(array("type" => "text", "text" => $finalSend, "cache_control" => $cacheControlType)));
                        $systemContentForCacheFile = null;
                    } else {

                        $environmental = extract_and_remove_section($systemContentCurrent, 'Environmental Context');
                        $additional = extract_and_remove_section($systemContentCurrent, 'Additional Information');
                        $dynamicEnvironment = $environmental . "\n\n" . $additional;

                        $finalSend = $systemContentCurrent . "\n" . $actionsText;

                        $finalMessagesToSend[] = array("role" => "system", "content" => array(array('type' => 'text', 'text' => $finalSend)), "cache_control" => $cacheControlType);

                        $systemContentForCacheFile = $systemContentCurrent;
                    }
                } elseif ($processedFirstSystem && !empty($trimmedSystemContent)) {
                    $cToSend = array(array('type' => 'text', 'text' => $trimmedSystemContent, "cache_control" => $cacheControlType));
                    $finalMessagesToSend[] = array('role' => 'system', 'content' => $cToSend);
                }
            }
        }


        if ($systemContentForCacheFile !== null) {
            connector_cacheWriteToFile($cacheSystemFile, $systemContentForCacheFile, $currentTime, null, null);
            error_log("{$logPrefix} System cache updated.");
        }
        // --- End System Processing ---
        // --- Step 2: Process Combined Dialogue History ---
        $needsRebuild = false;
        $startIndexForAddingIndividualMessages = 0;
        $hitDebugInfo = array('status' => '', 'reason' => '', 'lastCachedIndex' => 'N/A', 'lastCachedHash' => 'N/A', 'foundIndex' => -1, 'addedCount' => 0);

        if (!$isCombinedDialogueCacheValid) {
            error_log("--------==----- {$logPrefix} Combined dialogue cache miss/expired/invalid. Triggering rebuild.");
            $hitDebugInfo['status'] = 'MISS';
            $hitDebugInfo['reason'] = 'Expired/Invalid';
            $needsRebuild = true;
        } else {
            error_log("--------=----- {$logPrefix} Combined dialogue cache hit detected. Validating anchor...");
            $lastCachedIndex = isset($cacheCombinedDialogueMessages['lastIndex']) ? $cacheCombinedDialogueMessages['lastIndex'] : -1;
            $lastCachedHash = isset($cacheCombinedDialogueMessages['lastHash']) ? $cacheCombinedDialogueMessages['lastHash'] : null;
            $hitDebugInfo['lastCachedIndex'] = $lastCachedIndex;
            $hitDebugInfo['lastCachedHash'] = (!empty($lastCachedHash)) ? $lastCachedHash : 'N/A'; //PHP 5 empty check

            if (isset($cacheCombinedDialogueMessages["content"]) && !empty(rtrim($cacheCombinedDialogueMessages["content"], "\n"))) {
                $finalMessagesToSend[] = array(
                    'role' => "user",
                    "content" => array(
                        array(
                            "type" => "text",
                            "text" => rtrim($cacheCombinedDialogueMessages["content"], "\n"),
                            "cache_control" => $cacheControlType
                        )
                    )
                );
            }

            $foundMatchIndex = -1;
            if ($lastCachedIndex > -1 && !empty($lastCachedHash)) { // PHP 5 empty check
                for ($idx = 0; $idx < $n_ctxsize; $idx++) {
                    if (!isset($contextDataOrig[$idx]))
                        continue;
                    $element = $contextDataOrig[$idx];
                    if (isset($element["role"]) && ($element["role"] == "user" || $element["role"] == "assistant")) {
                        $contentString = '';
                        if (is_string($element['content'])) {
                            $contentString = $element['content'];
                        } elseif (
                            is_array($element['content']) && isset($element['content'][0]['type']) &&
                            $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])
                        ) {
                            $contentString = $element['content'][0]['text'];
                        }

                        if (md5(trim($contentString)) === $lastCachedHash) {
                            $foundMatchIndex = $idx;
                            error_log("{$logPrefix} Cache Anchor FOUND: Index {$foundMatchIndex} matches hash '{$lastCachedHash}'.");
                            $hitDebugInfo['status'] = 'HIT';
                            $hitDebugInfo['reason'] = 'Anchor Found';
                            $hitDebugInfo['foundIndex'] = $foundMatchIndex;
                            break;
                        }
                    }
                }

                if ($foundMatchIndex == -1) {
                    error_log("CRITICAL: Cache Anchor Hash '{$lastCachedHash}' (from file index {$lastCachedIndex}) NOT FOUND... Forcing REBUILD.");
                    $hitDebugInfo['status'] = 'MISS';
                    $hitDebugInfo['reason'] = 'Anchor Hash Not Found - Forced Rebuild';
                    $needsRebuild = true;
                    // Keep only system messages
                    $systemMessagesBackup = array();
                    foreach ($finalMessagesToSend as $msg) {
                        if (isset($msg['role']) && $msg['role'] === 'system') {
                            $systemMessagesBackup[] = $msg;
                        }
                    }
                    $finalMessagesToSend = $systemMessagesBackup;
                } else {
                    $startIndexForAddingIndividualMessages = $foundMatchIndex + 1;
                }
            } elseif ($lastCachedIndex == -1) {
                error_log("{$logPrefix} Cache hit on empty cache (index {$lastCachedIndex}). Proceeding without anchor search.");
                $hitDebugInfo['status'] = 'HIT';
                $hitDebugInfo['reason'] = 'Cache Empty (Index -1)';
                $startIndexForAddingIndividualMessages = 0;
            } else {
                error_log("WARNING: Cache inconsistency detected (Index {$lastCachedIndex}, Hash '{$lastCachedHash}'). Forcing REBUILD.");
                $hitDebugInfo['status'] = 'MISS';
                $hitDebugInfo['reason'] = 'Cache Inconsistency - Forced Rebuild';
                $needsRebuild = true;
                // Keep only system messages
                $systemMessagesBackup = array();
                foreach ($finalMessagesToSend as $msg) {
                    if (isset($msg['role']) && $msg['role'] === 'system') {
                        $systemMessagesBackup[] = $msg;
                    }
                }
                $finalMessagesToSend = $systemMessagesBackup;
            }
        }

        if ($needsRebuild) {
            // Build combined dialogue cache using the helper function
            $combinedDialogueResult = connector_buildCombinedDialogue($contextDataOrig, $numMessagesToKeepUncached, $this->name, $herikaName);

            // Keep system messages from before
            $systemMessagesBackup = array();
            foreach ($finalMessagesToSend as $msg) {
                if (isset($msg['role']) && $msg['role'] === 'system') {
                    $systemMessagesBackup[] = $msg;
                }
            }
            $finalMessagesToSend = $systemMessagesBackup;

            // Add the combined content as a user message
            if (isset($combinedDialogueResult['content']) && !empty(rtrim($combinedDialogueResult['content'], "\n"))) {
                $finalMessagesToSend[] = array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => rtrim($combinedDialogueResult['content'], "\n"),
                            'cache_control' => $cacheControlType
                        )
                    )
                );
            }

            // Write the combined cache file
            $writeResult = connector_cacheWriteToFile(
                $cacheCombinedDialogueFile,
                $combinedDialogueResult['content'],
                $currentTime,
                $combinedDialogueResult['lastAggregatedIndex'],
                $combinedDialogueResult['lastAggregatedHash']
            );

            if ($writeResult) {
                error_log("[{$this->name}:{$herikaName}] Cache Rebuild SUCCESS: Wrote index {$combinedDialogueResult['lastAggregatedIndex']}, hash '{$combinedDialogueResult['lastAggregatedHash']}'.");
            } else {
                error_log("[{$this->name}:{$herikaName}] Cache Rebuild FAIL: Failed writing cache file.");
            }

            $startIndexForAddingIndividualMessages = isset($combinedDialogueResult['startIndexForIndividual']) ?
                $combinedDialogueResult['startIndexForIndividual'] : 0;
            $hitDebugInfo['lastCachedIndex'] = isset($combinedDialogueResult['lastAggregatedIndex']) ?
                $combinedDialogueResult['lastAggregatedIndex'] : -1;
            $rebuiltHash = isset($combinedDialogueResult['lastAggregatedHash']) ?
                $combinedDialogueResult['lastAggregatedHash'] : null;
            $hitDebugInfo['lastCachedHash'] = (!empty($rebuiltHash)) ? $rebuiltHash : 'N/A'; // PHP 5 empty check
        }


        // Add individual messages after the cached portion
        $contentTextToSend = [];
        error_log("{$logPrefix} Building final payload: Adding individual messages from original context index {$startIndexForAddingIndividualMessages} onwards.");
        for ($sendIdx = $startIndexForAddingIndividualMessages; $sendIdx < $n_ctxsize; $sendIdx++) {
            if (!isset($contextDataOrig[$sendIdx]))
                continue;
            $element = $contextDataOrig[$sendIdx];
            if (isset($element["role"]) && $element["role"] != "system") {
                $contentString = '';
                if (is_string($element['content'])) {
                    $contentString = $element['content'];
                } elseif (
                    is_array($element['content']) && isset($element['content'][0]['type']) &&
                    $element['content'][0]['type'] === 'text' && isset($element['content'][0]['text'])
                ) {
                    $contentString = $element['content'][0]['text'];
                }

                if (!empty(trim($contentString))) {
                    if (
                        is_array($element['content']) && isset($element['content'][0]['type']) &&
                        $element['content'][0]['type'] === 'text'
                    ) {
                        $contentTextToSend[] = ['type' => $element['content'][0]['type'], 'text' => $element['content'][0]['text']];
                    } else {
                        $contentTextToSend[] = array('type' => 'text', 'text' => $contentString);
                    }
                }
            }
        }
        //array_pop($contentTextToSend);

        // Get the index of the last element
        $lastIndex = count($contentTextToSend) - 2;

        // Make sure the array is not empty before trying to access the last element
        if ($lastIndex >= 0) {
            // Add a new field to the last entry
            // Let's say you want to add a field called "speaker"
            $contentTextToSend[$lastIndex]["cache_control"] = $cacheControlType;
        }

        $finalMessagesToSend[] = array('role' => 'user', 'content' => $contentTextToSend);

        // --- End Dialogue Processing ---



        // Check if there is assistant history to decide whether to add examples
        $hasAssistantHistory = false;
        foreach ($finalMessagesToSend as $msg) {
            if ($msg['role'] === 'assistant') {
                $hasAssistantHistory = true;
                break;
            }
        }

        // If there is no assistant history, add example interaction
        if (!$hasAssistantHistory) {
            error_log("{$logPrefix} Added example interaction since no assistant history was found.");
        }

        // Add final user instruction for JSON response format
        $jsonResponseInstruction = "Respond as {$characterName} would in this situation. Express your thoughts or dialogue naturally, then consider boldly using an appropriate action that aligns with your character\'s personality and objectives. Your response should feel authentic and progress the scene or conversation naturally. Provide variety in your responses, avoid repeating the same phrases while still being consistent with the character and maintaining scene continuity. You MUST respond with no more than 2-3 sentences and no more than 40 words. Use ONLY this JSON object to give your answer. Do not send any other characters outside of this JSON structure: {\"character\":\"{$characterName}\",\"listener\":\"specify who {$characterName} is talking to\",\"mood\":\"irritated|seductive|smirking|amused|sexy|playful|kindly|sardonic|mocking|assertive|assisting|smug|default|teasing|neutral|sarcastic|lovely|sassy\",\"action\":\"LetsRelax|StartBlowjob|ReadQuestJournal|StartAnal|Masturbate|StartVaginal|StartOrgy|RemoveClothes|PinchNipples|SheatheWeapon|Grope|StartThreesome|StopLooting|IncreaseWalkSpeed|InspectSurroundings|ListInventory|Hunt|GiveItem|TakeItem|WaitHere|StartLooting|ExchangeItems|Inspect|Attack|DecreaseArousal|TakeASeat|Talk|StartHandjob|DecreaseWalkSpeed|PutOnClothes|IncreaseArousal|Hug|Kiss|Molest|SpankAss|SpankTits|TravelTo|ReceiveCoinsFromPlayer|Fight|ExitLocation|GiveCoinsTo|GiveItemToActor|GoToSleep|UseSoulGaze|Trade|FollowTarget
\", \"target\":\"action's target|destination name\", \"message\":\"lines of dialogue\"}"
            . "\nYou MUST only answer with a JSON Object."
            . "\nDO NOT include any markdown, explanations, or conversation outside the JSON object."
            . "\nEnsure the first character of your response is '{'. Ensure the last character of your response is '}'.";
        $finalMessagesToSend[] = array(
            'role' => 'user',
            'content' => array(
                array(
                    'type' => 'text',
                    'text' => $jsonResponseInstruction,
                    'cache_control' => $cacheControlType
                )
            )
        );
        error_log("{$logPrefix} Added JSON response instruction as final user message.");


        $finalMessagesToSend[] = array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => $dynamicEnvironment)));
        // --- Final Debug Logging for Cache Hit/Miss ---

        // --- Final Debug Logging for Cache Hit/Miss ---
        try {
            $logTimestamp = date(DateTime::ATOM);
            $status = isset($hitDebugInfo['status']) ? $hitDebugInfo['status'] : 'UNKNOWN';
            $reason = isset($hitDebugInfo['reason']) ? $hitDebugInfo['reason'] : 'Unknown';
            $cIdx = isset($hitDebugInfo['lastCachedIndex']) ? $hitDebugInfo['lastCachedIndex'] : 'N/A';
            $cHash = isset($hitDebugInfo['lastCachedHash']) ? $hitDebugInfo['lastCachedHash'] : 'N/A';
            $fIdx = isset($hitDebugInfo['foundIndex']) ? $hitDebugInfo['foundIndex'] : -1;
        } catch (Exception $e) {
            error_log("{$logPrefix} Cache Debug Log Err: " . $e->getMessage());
        }
        // --- END Logging ----

        // Payload Construction
        $data = array(
            'model' => $model,
            'messages' => $finalMessagesToSend,
            'stream' => true,
            'temperature' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["temperature"])) ? $GLOBALS["CONNECTOR"][$this->name]["temperature"] : 1),
            'top_k' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_k"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_k"] : 0),
            'top_p' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_p"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_p"] : 1),
            'frequency_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["frequency_penalty"] : 0),
            'presence_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["presence_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["presence_penalty"] : 0),
            'repetition_penalty' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["repetition_penalty"])) ? $GLOBALS["CONNECTOR"][$this->name]["repetition_penalty"] : 1),
            'min_p' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["min_p"])) ? $GLOBALS["CONNECTOR"][$this->name]["min_p"] : 0),
            'top_a' => floatval((isset($GLOBALS["CONNECTOR"][$this->name]["top_a"])) ? $GLOBALS["CONNECTOR"][$this->name]["top_a"] : 0),
        );

        // Prepare tool definition for JSON mode
        $tools = array();
        if (isset($GLOBALS["CONNECTOR"][$this->name]["tools"]) && is_array($GLOBALS["CONNECTOR"][$this->name]["tools"]) && !empty($GLOBALS["CONNECTOR"][$this->name]["tools"])) {
            $tools = $GLOBALS["CONNECTOR"][$this->name]["tools"];
            $data['tools'] = $tools;

            // Only add tool_choice if tools are provided and non-empty
            if (isset($GLOBALS["CONNECTOR"][$this->name]["tool_choice"]) && is_string($GLOBALS["CONNECTOR"][$this->name]["tool_choice"])) {
                $data['tool_choice'] = $GLOBALS["CONNECTOR"][$this->name]["tool_choice"];
            }
        }

        // Additional settings (stop sequences, max tokens, etc.)
        if (isset($GLOBALS["CONNECTOR"][$this->name]["stop"]) && is_array($GLOBALS["CONNECTOR"][$this->name]["stop"])) {
            $validStop = array();
            foreach ($GLOBALS["CONNECTOR"][$this->name]["stop"] as $stopSeq) {
                if (is_string($stopSeq) && !empty($stopSeq)) {
                    $validStop[] = $stopSeq;
                }
            }
            if (!empty($validStop)) {
                $data["stop_sequences"] = $validStop;
            }
        } else {
            // Default stop sequence if not specified
            $data["stop_sequences"] = array("USER");
        }

        $effectiveMaxTokens = null;
        if (isset($customParms["MAX_TOKENS"])) {
            $maxTokensValue = $customParms["MAX_TOKENS"] + 0;
            if ($maxTokensValue >= 0) {
                $effectiveMaxTokens = $maxTokensValue;
            }
        } else {
            if (isset($MAX_TOKENS))
                $effectiveMaxTokens = $MAX_TOKENS;
        }
        if (isset($GLOBALS["FORCE_MAX_TOKENS"])) {
            $forceMaxTokensValue = $GLOBALS["FORCE_MAX_TOKENS"] + 0;
            if ($forceMaxTokensValue >= 0) {
                $effectiveMaxTokens = $forceMaxTokensValue;
            }
        }
        if ($effectiveMaxTokens !== null) {
            if ($effectiveMaxTokens > 0) {
                $data["max_tokens"] = (int) $effectiveMaxTokens;
            } else {
                unset($data["max_tokens"]);
            }
        } else {
            if (isset($data["max_tokens"]))
                unset($data["max_tokens"]);
        }

        // Add provider information if available
        if (!empty($GLOBALS["CONNECTOR"][$this->name]["PROVIDER"])) {
            $providers = explode(",", $GLOBALS["CONNECTOR"][$this->name]["PROVIDER"]);
            $data["provider"] = array("order" => $providers);
        } else {
            $data["provider"] = array("order" => array("Anthropic"));
        }

        // Add transforms (empty array as in openrouterjson.php)
        $data["transforms"] = array();

        // Debug & Request Prep
        $GLOBALS["DEBUG_DATA"]["full"] = ($data);
        $this->_dataSent = json_encode($data, JSON_PRETTY_PRINT);
        try {
            $sysCacheLog = 'Sys:' . (is_array($cacheSystemMessages) && isset($cacheSystemMessages['content']) && $systemContentForCacheFile === null ? 'MATCH' : 'MISMATCH/WRITE');
            $uaStatus = isset($hitDebugInfo['status']) ? $hitDebugInfo['status'] : 'UNKNOWN';
            $uaReason = isset($hitDebugInfo['reason']) ? $hitDebugInfo['reason'] : '';
            $uaCacheLog = "UA:" . $uaStatus;
            if ($uaStatus === 'MISS' && !empty($uaReason)) {
                $uaCacheLog .= '(' . $uaReason . ')';
            }
            $cacheStatus = $sysCacheLog . '/' . $uaCacheLog;
            $finalMsgCount = isset($finalMessagesToSend) ? count($finalMessagesToSend) : 0;
            $logEntry = sprintf(
                "[%s] [%s:%s] [%s]\nPayload (%d msgs):\n%s\n---\n",
                date(DATE_ATOM),
                $this->name,
                $herikaName,
                $cacheStatus,
                $finalMsgCount,
                var_export($data, true)
            );
            @file_put_contents(__DIR__ . "/../log/context_sent_to_llm.log", $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("{$logPrefix} Context Log Err: " . $e->getMessage());
        }

        // API Request Preparation
        $apiKey = isset($GLOBALS["CONNECTOR"][$this->name]["API_KEY"]) ? $GLOBALS["CONNECTOR"][$this->name]["API_KEY"] : '';
        if (empty($apiKey)) {
            error_log("{$logPrefix} API Key missing!");
            return null;
        }
        $headers = array(
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
            "HTTP-Referer: https://www.nexusmods.com/skyrimspecialedition/mods/126330",
            "X-Title: CHIM",
            "anthropic-beta: extended-cache-ttl-2025-04-11" // Keep cache header
        );

        $timeout = isset($GLOBALS["HTTP_TIMEOUT"]) ? (int) $GLOBALS["HTTP_TIMEOUT"] : 60;
        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($data),
                'timeout' => $timeout,
                'ignore_errors' => true
            )
        );

        $context = stream_context_create($options);

        // Stream Opening
        $this->primary_handler = null;
        $this->_rawbuffer = "";
        $this->_buffer = "";
        $this->_forcedClose = false;
        $this->_jsonResponsesEncoded = array();

        try {
            $this->primary_handler = $this->send($url, $context);
        } catch (Exception $e) {
            error_log("fopen Exception [{$this->name}:{$herikaName}]: " . $e->getMessage());
            return null;
        }

        if (!$this->primary_handler) {
            $error = error_get_last();
            $errMsg = isset($error['message']) ? $error['message'] : 'fopen returned false';
            error_log("Stream Open Fail [{$this->name}:{$herikaName}]: {$errMsg}");
            if (isset($http_response_header) && is_array($http_response_header)) {
                error_log("HTTP Headers on fail: " . implode("\n", $http_response_header));
            }
            return null;
        }

        try {
            $logStartMsg = "\n== " . date(DATE_ATOM) . " [{$this->name}:{$herikaName}] STREAM START\n\n";
            @file_put_contents(__DIR__ . "/../log/output_from_llm.log", $logStartMsg, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("{$logPrefix} Output Log Start Err: " . $e->getMessage());
        }

        return true;
    }

    // The remaining methods are unchanged from the original JSON connector
    public function send($url, $context)
    {
        if (isset($GLOBALS['mockConnectorSend']) && is_callable($GLOBALS['mockConnectorSend'])) {
            return call_user_func($GLOBALS['mockConnectorSend'], $url, $context);
        }
        return @fopen($url, 'r', false, $context);
    }

    public function process()
    {
        global $alreadysent;
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        if ($this->isDone())
            return "";
        $line = @fgets($this->primary_handler);
        if ($line === false) {
            if (feof($this->primary_handler)) {
                return "";
            } else {
                $error = error_get_last();
                $errMsg = isset($error['message']) ? $error['message'] : 'fgets error';
                error_log("Read Err [{$this->name}:{$herikaName}]: {$errMsg}");
                $this->_rawbuffer .= "\nRead Err: {$errMsg}\n";
                $this->_forcedClose = true;
                return -1;
            }
        }

        try {
            @file_put_contents(__DIR__ . "/../log/debugStream.log", $line, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
        }

        $this->_rawbuffer .= $line;
        $buffer = "";

        if (strpos($line, 'data: ') === 0) {
            $jsonData = trim(substr($line, 6));
            if ($jsonData === '[DONE]') {
                return "";
            }
            if (!empty($jsonData)) {
                $data = json_decode($jsonData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    // Handle Anthropic and OpenAI formats
                    if (isset($data['type'])) { // Anthropic Format
                        switch ($data['type']) {
                            // Handle text delta content
                            case 'content_block_delta':
                                if (isset($data['delta']['type']) && $data['delta']['type'] === 'text_delta' && isset($data['delta']['text'])) {
                                    $buffer = $data['delta']['text'];
                                    $this->_buffer .= $buffer;
                                }
                                break;

                            // Handle JSON tool calls in Anthropic format
                            case 'content_block_start':
                                if (isset($data['content_block']['type']) && $data['content_block']['type'] === 'tool_use') {
                                    // Process tool_use start
                                    $this->_jsonResponsesEncoded[] = json_encode($data);
                                }
                                break;

                            case 'content_block_delta':
                                if (isset($data['delta']['type']) && $data['delta']['type'] === 'tool_use_delta') {
                                    // Accumulate tool_use delta parts
                                    $this->_jsonResponsesEncoded[] = json_encode($data);
                                }
                                break;

                            case 'content_block_stop':
                                // End of a tool_use block
                                // We'll process this complete tool use in processActions
                                break;

                            // Handle stop signals
                            case 'message_delta':
                                if (isset($data['delta']['stop_reason']) && $data['delta']['stop_reason'] !== null) {
                                    error_log("[{$this->name}:{$herikaName}] Stop (delta): " . $data['delta']['stop_reason']);
                                    $this->_forcedClose = true;
                                }
                                break;

                            case 'message_stop':
                                error_log("[{$this->name}:{$herikaName}] Stop (message_stop). Usage:" .
                                    (isset($data['message']['usage']) ? json_encode($data['message']['usage']) : 'N/A'));

                                // Log cache efficiency metrics if available
                                if (isset($data['message']['usage'])) {
                                    $usage = $data['message']['usage'];
                                    $cacheRead = isset($usage['cache_read_input_tokens']) ? $usage['cache_read_input_tokens'] : 0;
                                    $cacheCreate = isset($usage['cache_creation_input_tokens']) ? $usage['cache_creation_input_tokens'] : 0;
                                    $normalInput = isset($usage['input_tokens']) ? $usage['input_tokens'] : 0;
                                    $totalConsideredInput = $cacheRead + $cacheCreate + $normalInput;
                                    $efficiency = ($totalConsideredInput > 0) ? round(($cacheRead / $totalConsideredInput * 100), 1) : 0;
                                    $logPerfEntry = sprintf(
                                        "[%s] ANTHROCACHE_JSON %s: Read:%d Create:%d New:%d TotalIn:%d Efficiency:%.1f%%\n",
                                        date(DATE_ATOM),
                                        $herikaName,
                                        $cacheRead,
                                        $cacheCreate,
                                        $normalInput,
                                        $totalConsideredInput,
                                        $efficiency
                                    );
                                    @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . "_anthropic_cache_perf.log", $logPerfEntry, FILE_APPEND);
                                }

                                $this->_forcedClose = true;
                                break;

                            case 'error':
                                $eM = print_r((isset($data['error']) ? $data['error'] : $data), true);
                                error_log("Stream Err (Anthropic): {$eM}");
                                $this->_rawbuffer .= "\nErr (Anthropic):{$eM}\n";
                                $this->_forcedClose = true;
                                return -1;

                            case 'ping':
                                // Ignore ping events
                                break;

                            default:
                                error_log("[{$this->name}:{$herikaName}] Unhandled Anthropic Type: " . $data['type']);
                                break;
                        }
                    } elseif (isset($data["choices"][0]["delta"])) { // OpenAI Format
                        // Handle OpenAI format text content
                        if (isset($data["choices"][0]["delta"]["content"])) {
                            $buffer = $data["choices"][0]["delta"]["content"];
                            $this->_buffer .= $buffer;
                        }

                        // Handle OpenAI tool_calls in delta format
                        if (isset($data["choices"][0]["delta"]["tool_calls"])) {
                            $this->_jsonResponsesEncoded[] = json_encode($data);
                        }

                        // Handle OpenAI finish_reason
                        if (isset($data["choices"][0]["finish_reason"]) && $data["choices"][0]["finish_reason"] !== null) {
                            error_log("[{$this->name}:{$herikaName}] Stop (choice): " . $data["choices"][0]["finish_reason"]);
                            $this->_forcedClose = true;
                        }
                    } elseif (isset($data['error'])) { // Generic Error
                        $eM = print_r($data['error'], true);
                        error_log("Stream Err (Generic): {$eM}");
                        $this->_rawbuffer .= "\nErr (Generic):{$eM}\n";
                        $this->_forcedClose = true;
                        return -1;
                    }
                } else {
                    error_log("JSON Decode Err [{$this->name}:{$herikaName}]: " . json_last_error_msg() . " Data: " . substr($jsonData, 0, 150) . "...");
                }
            }
        } elseif (trim($line) === "event: message_stop") {
            error_log("[{$this->name}:{$herikaName}] Explicit stream end event received.");
            $this->_forcedClose = true;
        } elseif (!empty(trim($line))) {
            // Log unexpected non-SSE line
            error_log("Unexpected non-SSE line [{$this->name}:{$herikaName}]: " . substr(trim($line), 0, 150) . "...");
            $errorData = @json_decode(trim($line), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($errorData) && isset($errorData['error'])) {
                $eM = print_r($errorData['error'], true);
                error_log("Non-stream Err: {$eM}");
                $this->_rawbuffer .= "\nNon-stream Err:{$eM}\n";
                $this->_forcedClose = true;
                return -1;
            }
        }

        // Return Empty or Minimal Content: Avoid returning full JSON to prevent duplication in output
        // Extract message snippet if possible for real-time display
        if (!empty($buffer)) {
            $tempJson = json_decode($this->_buffer, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($tempJson['message']) && !empty($tempJson['message'])) {
                return $tempJson['message'];
            }
        }
        return ""; // Return empty string if no message to display yet, commands handled in processActions()
    }




    public function close()
    {
        if ($this->primary_handler) {
            @fclose($this->primary_handler);
            $this->primary_handler = null;
        }
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';

        try {
            $raw = isset($this->_rawbuffer) ? $this->_rawbuffer : '<empty>';
            $proc = isset($this->_buffer) ? $this->_buffer : '<empty>';

            // Track JSON responses for later processing
            $jsonResponses = isset($this->_jsonResponsesEncoded) && is_array($this->_jsonResponsesEncoded) ?
                implode("\n", $this->_jsonResponsesEncoded) : "<no JSON responses>";

            $logContent = sprintf(
                "Raw Stream Data:\n%s\n\nProcessed Text:\n%s\n\nJSON Responses:\n%s\n\n[%s] [%s:%s] END STREAM\n==\n",
                $raw,
                $proc,
                $jsonResponses,
                date(DATE_ATOM),
                $this->name,
                $herikaName
            );

            @file_put_contents(__DIR__ . "/../log/output_from_llm.log", $logContent, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("[{$this->name}:{$herikaName}] Close Log Err: " . $e->getMessage());
        }

        // Do NOT return the full buffer to avoid duplicating JSON output; commands are handled via processActions()
        $this->_rawbuffer = "";
        $this->_functionName = null;
        $this->_parameterBuff = "";
        $this->_forcedClose = false;
        // Don't clear _buffer or _jsonResponsesEncoded as they may be needed by processActions()

        return ""; // Return empty string to prevent duplication of full JSON response
    }



    public function processActions()
    {
        global $alreadysent;
        $this->_commandBuffer = array();
        $herikaName = isset($GLOBALS["HERIKA_NAME"]) ? $GLOBALS["HERIKA_NAME"] : 'default_herika';
        error_log("start process actions");
        // First, attempt to parse JSON directly from the buffer (for responses not using tool calls)
        if (!empty($this->_buffer)) {
            // Attempt to extract valid JSON from buffer (handle partial or malformed JSON)
            $jsonStart = strpos($this->_buffer, '{');
            $jsonEnd = strrpos($this->_buffer, '}');
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $possibleJson = substr($this->_buffer, $jsonStart, $jsonEnd - $jsonStart + 1);
                $parsedResponse = json_decode($possibleJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsedResponse)) {
                    error_log("[{$this->name}:{$herikaName}] Parsed JSON directly from buffer: " . json_encode($parsedResponse));
                    if (isset($parsedResponse['action']) && !empty($parsedResponse['action'])) {
                        $target = isset($parsedResponse['target']) ? $parsedResponse['target'] : '';
                        $character = isset($parsedResponse['character']) ? $parsedResponse['character'] : $herikaName;
                        $commandKey = md5("{$character}|command|{$parsedResponse['action']}@{$target}\r\n");
                        if (!isset($alreadysent[$commandKey]) || empty($alreadysent[$commandKey])) {
                            $functionCodeName = function_exists('getFunctionCodeName') ? getFunctionCodeName($parsedResponse['action']) : $parsedResponse['action'];
                            $functionCodeName = empty($functionCodeName) ? $parsedResponse['action'] : $functionCodeName;
                            error_log("FunctionCodeName: {$functionCodeName}");
                            error_log("actionName: {$parsedResponse['action']}");
                            $commandString = "{$character}|command|{$functionCodeName}@{$target}\r\n";
                            $this->_commandBuffer[] = $commandString;
                            $alreadysent[$commandKey] = $commandString;
                            error_log("[{$this->name}:{$herikaName}] Generated command from buffer JSON: {$commandString}");
                            if (ob_get_level()) {
                                @ob_flush();
                                @flush();
                            }
                        } else {
                            error_log("[{$this->name}:{$herikaName}] Command already sent (skipped from buffer JSON): {$character}|command|{$parsedResponse['action']}@{$target}");
                        }
                    } else {
                        error_log("[{$this->name}:{$herikaName}] No action field found in parsed JSON from buffer.");
                    }
                } else {
                    error_log("[{$this->name}:{$herikaName}] Failed to parse JSON from buffer: " . json_last_error_msg() . " Buffer excerpt: " . substr($possibleJson, 0, 150));
                }
            } else {
                error_log("[{$this->name}:{$herikaName}] No valid JSON boundaries found in buffer: " . substr($this->_buffer, 0, 150));
            }
        } else {
            error_log("[{$this->name}:{$herikaName}] Buffer is empty, no JSON to parse for actions.");
        }

        // Also process tool calls if any (from Anthropic or OpenAI format) as a fallback
        if (!empty($this->_jsonResponsesEncoded)) {
            try {
                $anthropicToolData = $this->processAnthropicToolUse();
                if (!empty($anthropicToolData)) {
                    foreach ($anthropicToolData as $toolAction) {
                        if (isset($toolAction['name']) && isset($toolAction['input'])) {
                            $this->addCommandFromTool($toolAction['name'], $toolAction['input'], $alreadysent);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("[{$this->name}:processActions] Error processing Anthropic tools: " . $e->getMessage());
            }

            try {
                $openaiToolData = $this->processOpenAIToolCalls();
                if (!empty($openaiToolData)) {
                    foreach ($openaiToolData as $toolAction) {
                        if (isset($toolAction['function']['name']) && isset($toolAction['function']['arguments'])) {
                            $this->addCommandFromTool($toolAction['function']['name'], $toolAction['function']['arguments'], $alreadysent);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("[{$this->name}:processActions] Error processing OpenAI tools: " . $e->getMessage());
            }
        }

        $this->_jsonResponsesEncoded = array(); // Clear after processing

        // Log the final command buffer for debugging
        if (!empty($this->_commandBuffer)) {
            error_log("[{$this->name}:{$herikaName}] Final Command Buffer: " . implode(", ", $this->_commandBuffer));
        } else {
            error_log("[{$this->name}:{$herikaName}] No commands generated in this cycle.");
        }

        return empty($this->_commandBuffer) ? array() : $this->_commandBuffer;
    }



    // Helper methods for processing JSON tool responses
    private function processAnthropicToolUse()
    {
        $toolUses = array();
        $currentTool = null;

        foreach ($this->_jsonResponsesEncoded as $jsonStr) {
            $data = @json_decode($jsonStr, true);
            if (json_last_error() !== JSON_ERROR_NONE)
                continue;

            // Start a new tool_use
            if (
                isset($data['type']) && $data['type'] === 'content_block_start' &&
                isset($data['content_block']['type']) && $data['content_block']['type'] === 'tool_use'
            ) {

                $currentTool = array(
                    'name' => isset($data['content_block']['name']) ? $data['content_block']['name'] : null,
                    'input' => isset($data['content_block']['input']) ? $data['content_block']['input'] : array()
                );
            }

            // Update tool_use with deltas
            if (
                isset($data['type']) && $data['type'] === 'content_block_delta' &&
                isset($data['delta']['type']) && $data['delta']['type'] === 'tool_use_delta'
            ) {

                if (isset($data['delta']['name']) && $currentTool !== null) {
                    $currentTool['name'] = $data['delta']['name'];
                }

                if (isset($data['delta']['input']) && is_array($data['delta']['input']) && $currentTool !== null) {
                    $currentTool['input'] = array_merge($currentTool['input'], $data['delta']['input']);
                }
            }

            // Finalize tool_use
            if (isset($data['type']) && $data['type'] === 'content_block_stop' && $currentTool !== null) {
                if (!empty($currentTool['name'])) {
                    $toolUses[] = $currentTool;
                }
                $currentTool = null;
            }
        }

        return $toolUses;
    }

    private function processOpenAIToolCalls()
    {
        $toolCalls = array();
        $pendingToolCalls = array();

        foreach ($this->_jsonResponsesEncoded as $jsonStr) {
            $data = @json_decode($jsonStr, true);
            if (json_last_error() !== JSON_ERROR_NONE)
                continue;

            if (isset($data['choices'][0]['delta']['tool_calls'])) {
                foreach ($data['choices'][0]['delta']['tool_calls'] as $toolCall) {
                    $id = isset($toolCall['id']) ? $toolCall['id'] : null;
                    if ($id === null)
                        continue;

                    if (!isset($pendingToolCalls[$id])) {
                        $pendingToolCalls[$id] = array(
                            'id' => $id,
                            'type' => isset($toolCall['type']) ? $toolCall['type'] : 'function',
                            'function' => array(
                                'name' => isset($toolCall['function']['name']) ? $toolCall['function']['name'] : '',
                                'arguments' => isset($toolCall['function']['arguments']) ? $toolCall['function']['arguments'] : ''
                            )
                        );
                    } else {
                        // Update existing tool call
                        if (isset($toolCall['function']['name'])) {
                            $pendingToolCalls[$id]['function']['name'] .= $toolCall['function']['name'];
                        }
                        if (isset($toolCall['function']['arguments'])) {
                            $pendingToolCalls[$id]['function']['arguments'] .= $toolCall['function']['arguments'];
                        }
                    }
                }
            }
        }

        // Process completed tool calls
        foreach ($pendingToolCalls as $id => $toolCall) {
            if (!empty($toolCall['function']['name'])) {
                // Try to parse arguments as JSON
                $arguments = $toolCall['function']['arguments'];
                try {
                    // If not valid JSON, wrap in quotes
                    $decoded = @json_decode($arguments, true);
                    if (json_last_error() !== JSON_ERROR_NONE && !empty($arguments)) {
                        $arguments = json_encode($arguments); // Convert to valid JSON string
                    }
                } catch (Exception $e) {
                    error_log("[{$this->name}:processOpenAIToolCalls] Error parsing arguments: " . $e->getMessage());
                }

                $toolCall['function']['arguments'] = $arguments;
                $toolCalls[] = $toolCall;
            }
        }

        return $toolCalls;
    }

    private function addCommandFromTool($name, $arguments, &$alreadysent)
    {
        if (empty($name))
            return;

        // Convert arguments to string if it's an array or object
        $parameterAsString = is_scalar($arguments) ? $arguments : json_encode($arguments);

        // Generate a unique hash for this command to avoid duplicates
        $commandKey = md5("Herika|command|{$name}@{$parameterAsString}\r\n");

        if (!isset($alreadysent[$commandKey])) {
            $functionCodeName = function_exists('getFunctionCodeName') ? getFunctionCodeName($name) : $name;
            $commandString = "Herika|command|{$functionCodeName}@{$parameterAsString}\r\n";
            $this->_commandBuffer[] = $commandString;
            $alreadysent[$commandKey] = $commandString;

            error_log("[{$this->name}:processActions] Generated command: {$commandString}");
            if (ob_get_level()) {
                @ob_flush();
                @flush();
            }
        } else {
            error_log("[{$this->name}:processActions] Command already sent (skipped): Herika|command|{$name}@{$parameterAsString}");
        }
    }

    public function isDone()
    {
        if ($this->_forcedClose) {
            if ($this->primary_handler) {
                @fclose($this->primary_handler);
                $this->primary_handler = null;
            }
            return true;
        }
        return !$this->primary_handler || feof($this->primary_handler);
    }
}
?>
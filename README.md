# chim-anthropic-cache-connector
Instructions:
Drag copy the contents inside the HerikaServer folder into your local HerikaServer folder:
\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer

In the CHIM webUI, set Configuration Depth to Experimental (use Crtl+F if struggling).

In the CHIM webUI, navigate to (use Crtl+F if struggling):
3.1. CONNECTOR openrouterjsonanthropic system_cache_strategy, set to ttl
3.2. CONNECTOR openrouterjsonanthropic system_cache_ttl set to at least 7200 (2 hours, can be longer)
3.3. CONNECTOR openrouterjsonanthropic dialogue_cache_ttl set to at least 7200 (2 hours, can be longer)
3.4. CONNECTOR openrouterjsonanthropic dialogue_cache_uncached_count set to 15
3.5. Copy to all profiles

In the CHIM webUI, navigate to (use Crtl+F if struggling):
4.1.AI/LLM Connectors Selection
4.2. Check openrouterjsonanthropic and openrouteranthropic
4.3. Cycle through Current AI Service to openrouterjsonanthropic
4.4. Copy to all profiles

If it exists, delete everything in the folder,
\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\temp

Additional Notes:
These connectors are to be used with OpenRouter
If it is unclear what any setting does, there are descriptions in the webUI

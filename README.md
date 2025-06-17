# chim-anthropic-cache-connector
Instructions:
Download the latest Version from the Releases.

Drag copy the contents inside the HerikaServer folder into your local HerikaServer folder:
\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer

In the CHIM webUI, set Configuration Depth to Experimental (use Crtl+F if struggling).

In the CHIM webUI, navigate to (use Crtl+F if struggling):
4.1.AI/LLM Connectors Selection
4.2. Check openrouterjsonanthropic and openrouteranthropic
4.3. Cycle through Current AI Service to openrouterjsonanthropic

If it exists, delete everything in the folder,
\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\temp

To test, simply use the troubleshooting page.
YOU MUST clear the contents of the \wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\temp folder every time you are done using Troubleshooting or Immersion->Chat to test.


Additional Notes:

These connectors are to be used with OpenRouter

Everytime you change your System Prompt, Character Biography or Dynamic Biography, you should clear the temp folder to see the effects.

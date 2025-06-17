# CHIM Anthropic Cache Connector

-----

This guide will help you set up and use the CHIM Anthropic Cache Connector. These connectors are designed to be used with **OpenRouter**.

## Installation

1.  **Download:** Get the latest version from the [Releases](https://github.com/cleanestpoison/chim-anthropic-cache-connector/releases) page.
2.  **Copy Files:** Drag and copy the contents of the `HerikaServer` folder into your local HerikaServer directory.
      * **Path:** `\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`

## CHIM WebUI Configuration

1.  **Configuration Depth:** In the CHIM webUI, set **Configuration Depth** to **Experimental**. (Use `Ctrl+F` to search if needed.)
2.  **AI/LLM Connectors Selection:** Navigate to **4.1. AI/LLM Connectors Selection**. (Use `Ctrl+F` to search if needed.)
3.  **Select Connectors:** Check both `openrouterjsonanthropic` and `openrouteranthropic`.
4.  **Set Current AI Service:** Cycle through **Current AI Service** until `openrouterjsonanthropic` is selected.

## Temp Folder Management

  * **Before Testing:** If it exists, **delete everything** in the following folder:
      * **Path:** `\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\temp`
  * **After Testing:** **YOU MUST clear the contents** of the `\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\temp` folder every time you finish using **Troubleshooting** or **Immersion-\>Chat** to test.

## Testing

To test the connector, simply use the **troubleshooting page** in the CHIM webUI.

## Important Notes

  * **System Prompt Changes:** Every time you change your **System Prompt**, **Character Biography**, or **Dynamic Biography**, you should **clear the `temp` folder** to ensure the changes take effect.

-----

Feel free to reach out if you encounter any issues during the setup\!

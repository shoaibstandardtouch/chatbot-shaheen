# MxChat Bulk PDF Upload Add-on

A lightweight, robust WordPress helper plugin designed to add bulk PDF upload capabilities to the [MxChat](https://wordpress.org/plugins/mxchat-basic/) AI chatbot plugin's Knowledge Base section.

## Features

- **Bulk Upload Support:** Changes the single-file PDF upload selector into a multiple-file selector, allowing you to select and upload dozens of PDFs at once.
- **Unified Processing Queue:** Combines all pages from all uploaded PDFs into a single, unified queue. This uses MxChat's native background processing framework to vectorize pages one-by-one.
- **Real-Time Progress Tracking:** Fully integrates with the existing MxChat progress bar, displaying the overall progress percentage (e.g., "Progress: Page X of Y pages (Z%)") across all uploaded documents.
- **Clean File Deletion:** Hooks into the transient lifecycle of the active PDF queue, ensuring that all temporary PDF files are completely deleted from your server once processing completes or is stopped.
- **No core file modification:** Keeps the core `mxchat-basic` plugin intact, allowing you to safely update it in the future without losing your bulk upload capabilities.

## Installation

1. Download the ZIP file of this repository directly from GitHub (e.g., `chatbot-shaheen-main.zip`).
2. In your WordPress Dashboard, go to **Plugins > Add New > Upload Plugin**.
3. Upload the downloaded `.zip` file and click **Install Now**.
4. Activate the **MxChat Bulk PDF Upload Add-on** plugin.

## How to Use

1. Navigate to **MxChat > Knowledge** in your WordPress dashboard.
2. Choose the **PDF Upload** import method.
3. Click the file selector (or drag-and-drop) to choose **multiple PDF files** at once.
4. Click **Import PDF(s)**.
5. The progress bar will appear and process all the pages of your uploaded documents in the background.

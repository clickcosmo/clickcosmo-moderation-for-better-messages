# Message Board Moderation for Better Messages

Regular WordPress plugin for Better Messages.

## Install

1. Upload and activate the plugin ZIP from **Plugins > Add New > Upload Plugin**.
2. Go to **Moderation for Better Messages** in the WordPress admin menu.
3. Add moderated boards one per line:
   `thread_id|Board Name`
4. Create or edit your moderation page and add:
   `[message_board_moderation_bm]`
5. Confirm the moderation page path in plugin settings.

## Sample values

Use the room ID and room Name in the settings textarea:

988|SHARE YOUR EVENTS<br>
989|FIND MUSICIANS

## Important

This plugin uses "read" permission because it is designed to work with PRZ Setlist Builder. If you are not using the PRZ Setlist Builder, you may have to change the permission to "manage_options", or secure the page where the shortcode is placed.

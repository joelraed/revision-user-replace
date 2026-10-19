# Revision User Replace

Drupal 10/11 module for reassigning the authors of existing node and media revisions, including historical revisions, without changing content ownership.

**Warning:** This changes revision history directly via SQL. Back up the database and test on staging first.

The PHP classes live under `src/`. Integration into an existing Drupal `inside_user` module requires its routing and permission configuration.

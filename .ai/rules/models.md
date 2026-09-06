---
paths:
  - 'app/Models/**'
---

# Models

## Declare mass assignment with attributes, and keep credentials out
Use the #[Fillable([...])] and #[Hidden([...])] class attributes, not protected $fillable / protected $hidden. Identity and credential columns — athlete ids, OAuth tokens, owning foreign keys — stay out of #[Fillable] and are assigned explicitly by the action that writes them, so no request body can plant them.

## Every model carries a full @property docblock
Declare every column and relation as @property / @property-read in the model's class docblock, kept in sync with the migration. Larastan runs at level 5 and no IDE-helper file is generated, so this docblock is the only type source static analysis has.

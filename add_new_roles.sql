-- First, drop the existing constraint
ALTER TABLE group_members DROP CONSTRAINT CK__group_memb__role__4222D4EF;

-- Then, create a new constraint with the additional roles
ALTER TABLE group_members ADD CONSTRAINT CK__group_memb__role__4222D4EF 
CHECK (role IN ('admin', 'treasurer', 'member', 'secretary', 'chairperson', 'vice_chairperson', 'organizer', 'coordinator'));

-- If you're using SQL Server, you might need to use this syntax instead:
-- ALTER TABLE group_members DROP CONSTRAINT CK__group_memb__role__4222D4EF;
-- ALTER TABLE group_members ADD CONSTRAINT CK__group_memb__role__4222D4EF 
-- CHECK (role IN ('admin', 'treasurer', 'member', 'secretary', 'chairperson', 'vice_chairperson', 'organizer', 'coordinator')); 
# Testing the Flexible Mobile Entry UI

## Available Test Routes

The new flexible component-based UI has 4 example routes you can visit:

### 1. With Date Navigation (Full Featured)
**URL:** `/flexible/with-nav`

Shows the complete mobile entry experience with:
- Date navigation (prev/today/next)
- Title with subtitle
- Success and info messages
- Summary statistics (4 items)
- Add Exercise button
- Item selection list with 3 exercises
- Single workout form (Bench Press)
- Logged items section with 2 items

**Try:**
- Click prev/next to navigate dates
- Click "Add Exercise" to show item list
- Use the filter to search exercises
- Increment/decrement numeric fields
- Submit the form (will show validation since routes are placeholders)

### 2. Without Navigation (Minimal)
**URL:** `/flexible/without-nav`

Shows a minimal standalone form with:
- Title only
- Single form (no navigation, no summary, no item list)

**Use case:** Quick data entry without date context

### 3. Multiple Forms
**URL:** `/flexible/multiple-forms`

Shows multiple forms on one page:
- Title
- Info message
- 3 workout forms (Bench Press, Squats, Deadlift)

**Use case:** Pre-programmed workout with multiple exercises

### 4. Custom Component Order
**URL:** `/flexible/custom-order`

Shows components in non-standard order:
- Messages first (warning)
- Then title
- Then form
- Summary at the end

**Use case:** Demonstrates complete flexibility in component ordering

## What to Test

### Navigation Component
- [ ] Prev/Next buttons work
- [ ] Today button works
- [ ] Date changes in URL
- [ ] Disabled state works (try modifying controller)

### Forms
- [ ] Increment/decrement buttons work
- [ ] Manual input works
- [ ] Min/max boundaries enforced
- [ ] Decimal increments work (0.5, 0.1)
- [ ] Comment field works
- [ ] Submit button works

### Item List
- [ ] Filter input works
- [ ] Clear filter button appears/works
- [ ] No results message shows when no matches
- [ ] Create form appears when no results
- [ ] Cancel button hides list

### Messages
- [ ] Different message types render correctly
- [ ] Prefix shows when present
- [ ] Multiple messages stack properly

### Summary
- [ ] Numbers format correctly
- [ ] Labels display properly
- [ ] Grid layout works on mobile

### Logged Items
- [ ] Items display correctly
- [ ] Edit/delete buttons work
- [ ] Confirmation dialogs appear
- [ ] Empty message shows when no items

### Responsive Design
- [ ] Test on mobile viewport (< 480px)
- [ ] Test on tablet viewport (480-768px)
- [ ] Test on desktop viewport (> 768px)
- [ ] Touch targets are adequate (44px minimum)

## Browser Console

Check for JavaScript errors:
1. Open browser dev tools (F12)
2. Go to Console tab
3. Visit each test route
4. Look for any errors

Common issues:
- Missing Font Awesome icons (check if CSS is loaded)
- JavaScript not loading (check network tab)
- Component rendering issues (check Elements tab)

## Modifying Examples

To test different configurations, edit `FlexibleWorkflowController.php`:

```php
// Add more forms
C::form('ex-4', 'Pull-ups')
    ->formAction('#')
    ->numericField('reps', 'Reps:', 10, 1, 1)
    ->build(),

// Change navigation
C::navigation()
    ->prev('← Previous', '#')
    ->next('Continue →', '#')  // No center button
    ->build(),

// Add more summary items
C::summary()
    ->item('calories', 2500, 'Calories')
    ->item('protein', 180, 'Protein (g)')
    ->item('carbs', 250, 'Carbs (g)')
    ->item('fat', 80, 'Fat (g)')
    ->build(),
```

## Next Steps

Once you've verified everything works:

1. Update `MobileEntryController::lifts()` to use ComponentBuilder
2. Test with real data from database
3. Update services (LiftLogService, etc.) to use ComponentBuilder
4. Update remaining methods (foods, measurements)
5. Remove old view once updates complete

## Troubleshooting

**Issue:** Styles not loading
- Check if `public/css/mobile-entry.css` exists
- Clear browser cache
- Check network tab for 404s

**Issue:** JavaScript not working
- Check if `public/js/mobile-entry.js` exists
- Look for console errors
- Verify script tag in view

**Issue:** Components not rendering
- Check component type matches filename
- Verify data structure in controller
- Look for Blade syntax errors in component files

**Issue:** Routes not found
- Run `php artisan route:clear`
- Run `php artisan route:cache`
- Check `routes/web.php` for typos

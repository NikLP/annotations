# LocalGov Drupal Annotations Demo Recipe

## Installation steps

- [Install LGD](https://docs.localgovdrupal.org/devs/workflows/installing-and-deploying-lgd.html#_1-initial-installation)
- [Install LGD demo](https://www.drupal.org/project/localgov_demo) which is
present in the default LGD site install
- Run the LGD demo recipe

## What this does

The recipe will:

- install the required modules
- set up annotations targets & types pertinent to the demo
- insert demo annotations

Bear in mind this is a work in progress; annotation content here was created by
AI, and has no necessary bearing on reality.

## 'Cooking' the recipe

Recipes in Drupal usually appear in the project root, ie outside of the webroot.

The foolproof approach is to *copy the recipe* from the annotations module into
that directory and run it with: `drush recipe ../recipes/annotations_demo_lgd`.

Or you can simply run `drush recipe modules/contrib/annotations/recipes/annotations_demo_lgd`

I offer two approaches here purely because annotations is a bit of a moveable
feast at the moment, the directory names on your install may differ, etc etc.

## What did I get, here?

The recipe inserts:

- annotation types of:
  - Editorial
  - Technical
  - Business rules

- annotation target config *and* annotations for:
  - Event content type
  - Subsite page content type
  - Banner primary paragraph type
  - Accordion paragraph type

## Where should I look to see this?

Ok for this demo I only present field annotations on forms. The overlay module
is an example of an annotations *consumer* module. This will show annotations
on edit forms for the content types and paragraph types listed above. If you
read the module's README pages, primarily the top-level one, you'll get a better
understanding of what's what here.

First let's add a new Event page, via `node/add/localgov_event`. You will see
that the form has some new elements that look like (?) by the fields. These
contain annotations. Click the (?) trigger and a modal will appear.

Ok this is pretty cool, but let's add a subsite page
(`node/add/localgov_subsites_page`). On this form we can see more of the same
triggers, but this form also uses additional form mechanisms.

Click Banner, and you'll see the banner paragraph type also has an annotation.
Click Banner primary, and oh cool, we can use annotations inside paragraphs
sub-forms!

A little more? Click the following in order:

- Page builder
- Add section (inside Page content)
- Any layout to add a section, then save
- `+` button to add a component
- Accordion

Voila, this has annotations too, on this form within a form (within a form?)

The overlay module also contains functionality for the front end. Let's enable
that and show it working.

- Navigate to the event page's manage fields section (`admin/structure/types/manage/localgov_event/display`).
- Enable the annotations overlay by moving the field out of the disabled section
into the active fields, setting the label to hidden.
- Save

Now create an Event, and you can see the annotations inserted into the page.

You'll have read the module's README so you understand that different annotation
types can be displayed to different roles, right? So annotations here could have
been set up differently, you might have a set just for displaying to (front)
end users. The annotation content here isn't really geared towards these folk.

*There is more to it than this.*

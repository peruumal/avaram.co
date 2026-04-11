<?php get_header(); ?>
<?php while (have_posts()):
    the_post(); ?>

    <section class="card">
        <h2>Directions to Property</h2>
        <p>Route from Meenakshi Amman Temple to D79-4,Kumaran street, AlagappanNagar, Madurai-625003:</p>
        <iframe width="100%" height="450" style="border:0; margin-top: 1rem; border-radius: 8px;" loading="lazy"
            allowfullscreen="" referrerpolicy="no-referrer-when-downgrade"
            src="https://maps.google.com/maps?output=embed&saddr=Meenakshi+Amman+Temple,+Madurai&daddr=V3xx%2B49C"
            title="Directions from Meenakshi Amman Temple to D79-4 Kumaran Street"></iframe>
    </section>

    <div class="editor-content">
        <?php the_content(); ?>
    </div>

<?php endwhile; ?>
<?php get_footer(); ?>
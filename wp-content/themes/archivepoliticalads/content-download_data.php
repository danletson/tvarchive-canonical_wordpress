	<form method="get" action="<?php bloginfo('url'); ?>/export" target="_blank">
<!--
		<div class="row download-row">
			<div class="col-lg-4">
				<div class="checkbox">
					<label>
						<input type="checkbox" name="data_include[]" value="location" checked="checked"> Location Data
					</label>
				</div>
			</div>
			<div class="col-lg-4">
				<div class="checkbox">
					<label>
						<input type="checkbox" name="data_include[]" value="date" checked="checked"> Date Data
					</label>
				</div>
			</div>
			<div class="col-lg-4">
				<div class="checkbox">
					<label>
						<input type="checkbox" name="data_include[]" value="notes" checked="checked"> Notes
					</label>
				</div>
			</div>
		</div>
-->
<!--
<div class="row download-row">
    <div class="col-lg-4">
        <div class="checkbox">
            <label>
                <input type="checkbox" name="data_include[]" value="station" checked="checked"> Station Information
            </label>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="checkbox">
            <label>
                <input type="checkbox" name="data_include[]" value="time" checked="checked"> Time Data
            </label>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="checkbox">
            <label>
                <input type="checkbox" name="data_include[]" value="metadata" checked="checked"> Item Metadata
            </label>
        </div>
    </div>
</div>
-->
		<div class="download-row last">
			<div class="download-btn">
                <?php
                    if(isset($ad_id)) {
                        ?>
        				<input type="hidden" name="ad_identifier" value="echo($ad_id);>" />
                        <?php
                    }
                ?>
				<input type="submit" id="download-data-button" class="button" value="Download CSV" />
			</div>
		</div>
	</form>

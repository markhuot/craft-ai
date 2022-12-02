(function($R)
{
    function resetIcon(button)
    {
        button.setIcon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" width="1.1em" height="1.1em"><rect x="4" y="3" width="12" height="2" rx="1" fill="currentColor"></rect><rect x="4" y="7" width="12" height="2" rx="1" fill="currentColor"></rect><rect x="4" y="11" width="3" height="2" rx="1" fill="currentColor"></rect><rect x="4" y="15" width="3" height="2" rx="1" fill="currentColor"></rect><rect x="8.5" y="11" width="3" height="2" rx="1" fill="currentColor"></rect><rect x="8.5" y="15" width="3" height="2" rx="1" fill="currentColor"></rect><rect x="13" y="11" width="3" height="2" rx="1" fill="currentColor"></rect></svg>');
    }

    function loadingIcon(button)
    {
        button.setIcon('<svg width="1.1em" height="1.1em" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">\n' +
            '<path fill-rule="evenodd" clip-rule="evenodd" d="M19 8.9646C19 13.3633 15.4961 16.9292 11.1739 16.9292C9.78875 16.9292 8.48764 16.563 7.35897 15.9205C6.99623 16.7048 6.21259 17.2478 5.30435 17.2478C4.0509 17.2478 3.03478 16.2137 3.03478 14.9381C3.03478 14.0137 3.56829 13.2162 4.33901 12.8471C3.70768 11.6984 3.34783 10.3743 3.34783 8.9646C3.34783 4.56587 6.85168 1 11.1739 1C15.4961 1 19 4.56587 19 8.9646ZM2.17391 19C2.82225 19 3.34783 18.4651 3.34783 17.8053C3.34783 17.1455 2.82225 16.6106 2.17391 16.6106C1.52558 16.6106 1 17.1455 1 17.8053C1 18.4651 1.52558 19 2.17391 19ZM11 14C13.7614 14 16 11.7614 16 9C16 6.23858 13.7614 4 11 4C8.23858 4 6 6.23858 6 9C6 11.7614 8.23858 14 11 14Z" fill="#545454"/>\n' +
            '<circle cx="11" cy="9" r="5" fill="#545454" class="craftai-loader"/>\n' +
            '</svg>\n');
    }

    $R.add('plugin', 'craftai-complete',
        {
            init: function(app)
            {
                this.app = app;
                this.toolbar = app.toolbar;
                this.insertion = app.insertion;
            },
            start: function ()
            {
                this.button = this.toolbar.addButton('craftai-complete', {
                    title: 'Complete text with AI',
                    api: 'plugin.craftai-complete.execute'
                });

                resetIcon(this.button)
            },
            execute: function ()
            {
                loadingIcon(this.button);

                const content = this.app.source.getCode();
                Craft.sendActionRequest('post', 'ai/text/complete', {
                    headers: {
                        'content-type': 'application/json',
                    },
                    data: {
                        content,
                    }
                }).then(({data: { text }}) => {
                    this.app.insertion.insertText(text);
                }).catch(({response: { data: { error, errors } }}) => {
                    if (error) {
                        Craft.cp.displayError(error);
                    }
                    for (const key in errors) {
                        if (key === 'input') {
                            Craft.cp.displayError('You must select text to use the edit feature.');
                        }
                        else {
                            for (const value of errors[key]) {
                                Craft.cp.displayError(value);
                            }
                        }
                    }
                }).finally(() => {
                    resetIcon(this.button);
                });
            }
        });
})(Redactor);

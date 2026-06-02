 const cases = document.querySelectorAll('.case-wrap');
    const dots = document.querySelectorAll('.dot');
    let current = 1;
    const total = cases.length;
    function getClass(i) {
        const diff = (i - current + total) %total;
        if (diff === 0) return 'active' ;
        if (diff === 1) return 'right' ;
        if (diff === total - 1) return 'left';
        return 'hidden';
    }
    function update () {
        cases.forEach((c, i) => {
            c.className = 'case-wrap ' +  getClass(i);
        });
    }
    setInterval(() => {
        current = (current + 1) %total;
        update();
    }, 2500);
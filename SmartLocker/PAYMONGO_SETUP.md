# Locker Payment Simulation

Run `database/migration_paymongo.sql` against the `SmartLocker` database. The locker payment flow is a local simulation for demonstration only. Students can simulate the listed rental prices, but no PayMongo API request is made and no real money is collected.

Students choose a one-week (₱50), one-month (₱100), or three-month (₱150) rental, review the amount on the local payment page, then submit. Successful simulated payment activates the reservation and creates the locker assignment immediately; no admin approval is required.
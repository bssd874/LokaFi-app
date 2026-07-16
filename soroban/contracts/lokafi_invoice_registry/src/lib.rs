#![no_std]
use soroban_sdk::{
    contract, contracterror, contractevent, contractimpl, contracttype, panic_with_error, Address,
    BytesN, Env, String,
};

#[contract]
pub struct LokaFiInvoiceRegistry;

#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub enum InvoiceStatus {
    Pending,
    Paid,
}

#[contracttype]
#[derive(Clone, Debug, Eq, PartialEq)]
pub struct InvoiceRecord {
    pub invoice_hash: BytesN<32>,
    pub merchant: Address,
    pub amount: i128,
    pub memo_hash: BytesN<32>,
    pub transaction_hash: Option<BytesN<32>>,
    pub payer: Option<Address>,
    pub status: InvoiceStatus,
    pub created_ledger: u32,
    pub created_at: u64,
    pub paid_ledger: Option<u32>,
    pub paid_at: Option<u64>,
}

#[contracttype]
#[derive(Clone)]
enum DataKey {
    Admin,
    Invoice(BytesN<32>),
    UsedTransaction(BytesN<32>),
}

#[contractevent(topics = ["invoice", "register"])]
pub struct InvoiceRegisteredEvent {
    #[topic]
    pub invoice_hash: BytesN<32>,
    pub merchant: Address,
    pub amount: i128,
    pub memo_hash: BytesN<32>,
}

#[contractevent(topics = ["invoice", "paid"])]
pub struct InvoicePaidEvent {
    #[topic]
    pub invoice_hash: BytesN<32>,
    pub transaction_hash: BytesN<32>,
    pub payer: Option<Address>,
}

#[contracterror]
#[derive(Copy, Clone, Debug, Eq, PartialEq)]
#[repr(u32)]
pub enum ContractError {
    AlreadyInitialized = 1,
    NotInitialized = 2,
    InvoiceAlreadyExists = 3,
    InvalidAmount = 4,
    InvoiceNotFound = 5,
    InvoiceAlreadyPaid = 6,
    TransactionAlreadyUsed = 7,
}

#[contractimpl]
impl LokaFiInvoiceRegistry {
    pub fn initialize(env: Env, admin: Address) {
        if env.storage().instance().has(&DataKey::Admin) {
            panic_with_error!(&env, ContractError::AlreadyInitialized);
        }

        admin.require_auth();
        env.storage().instance().set(&DataKey::Admin, &admin);
    }

    pub fn register_invoice(
        env: Env,
        invoice_hash: BytesN<32>,
        merchant: Address,
        amount: i128,
        memo_hash: BytesN<32>,
    ) {
        Self::require_admin(&env);

        if amount <= 0 {
            panic_with_error!(&env, ContractError::InvalidAmount);
        }

        let invoice_key = DataKey::Invoice(invoice_hash.clone());
        if env.storage().persistent().has(&invoice_key) {
            panic_with_error!(&env, ContractError::InvoiceAlreadyExists);
        }

        let record = InvoiceRecord {
            invoice_hash: invoice_hash.clone(),
            merchant: merchant.clone(),
            amount,
            memo_hash: memo_hash.clone(),
            transaction_hash: None,
            payer: None,
            status: InvoiceStatus::Pending,
            created_ledger: env.ledger().sequence(),
            created_at: env.ledger().timestamp(),
            paid_ledger: None,
            paid_at: None,
        };

        env.storage().persistent().set(&invoice_key, &record);
        InvoiceRegisteredEvent {
            invoice_hash,
            merchant,
            amount,
            memo_hash,
        }
        .publish(&env);
    }

    pub fn mark_invoice_paid(
        env: Env,
        invoice_hash: BytesN<32>,
        transaction_hash: BytesN<32>,
        payer: Option<Address>,
    ) {
        Self::require_admin(&env);

        let invoice_key = DataKey::Invoice(invoice_hash.clone());
        let mut record: InvoiceRecord = env
            .storage()
            .persistent()
            .get(&invoice_key)
            .unwrap_or_else(|| panic_with_error!(&env, ContractError::InvoiceNotFound));

        if record.status == InvoiceStatus::Paid {
            panic_with_error!(&env, ContractError::InvoiceAlreadyPaid);
        }

        let transaction_key = DataKey::UsedTransaction(transaction_hash.clone());
        if env.storage().persistent().has(&transaction_key) {
            panic_with_error!(&env, ContractError::TransactionAlreadyUsed);
        }

        record.status = InvoiceStatus::Paid;
        record.transaction_hash = Some(transaction_hash.clone());
        record.payer = payer.clone();
        record.paid_ledger = Some(env.ledger().sequence());
        record.paid_at = Some(env.ledger().timestamp());

        env.storage().persistent().set(&invoice_key, &record);
        env.storage().persistent().set(&transaction_key, &true);
        InvoicePaidEvent {
            invoice_hash,
            transaction_hash,
            payer,
        }
        .publish(&env);
    }

    pub fn get_invoice(env: Env, invoice_hash: BytesN<32>) -> Option<InvoiceRecord> {
        env.storage()
            .persistent()
            .get(&DataKey::Invoice(invoice_hash))
    }

    pub fn invoice_exists(env: Env, invoice_hash: BytesN<32>) -> bool {
        env.storage()
            .persistent()
            .has(&DataKey::Invoice(invoice_hash))
    }

    pub fn is_transaction_used(env: Env, transaction_hash: BytesN<32>) -> bool {
        env.storage()
            .persistent()
            .has(&DataKey::UsedTransaction(transaction_hash))
    }

    pub fn version(env: Env) -> String {
        String::from_str(&env, "lokafi_invoice_registry_v1")
    }

    fn require_admin(env: &Env) -> Address {
        let admin: Address = env
            .storage()
            .instance()
            .get(&DataKey::Admin)
            .unwrap_or_else(|| panic_with_error!(env, ContractError::NotInitialized));
        admin.require_auth();
        admin
    }
}

mod test;
